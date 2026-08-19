import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertBanner,
    AuditPager,
    AuditTable,
    DestructiveDialog,
    EmptyState,
    GuardedButton,
    Segmented,
} from '@/components/vault';
import type { ProjectPageProps } from '../types';

/**
 * The project's audit feed: append-only, hash-chained, failures included.
 *
 * Paging and the success/failure filter live in the URL and are resolved in
 * SQL, along with the rule that hides entries naming an environment this
 * viewer cannot see. That last one used to run in PHP over the newest 200
 * rows, which quietly ended a project's history at 200 entries.
 */
export function AuditScreen({
    project,
    auditLog,
    viewer,
}: {
    project: ProjectPageProps['project'];
    auditLog: ProjectPageProps['auditLog'];
    viewer: ProjectPageProps['viewer'];
}) {
    const [wiping, setWiping] = useState(false);

    const { rows, total, filter } = auditLog;
    const base = `/orgs/${project.organizationSlug}/projects/${project.slug}`;

    const go = (next: { page?: number; filter?: string }) =>
        router.get(
            base,
            {
                screen: 'audit',
                filter: next.filter ?? filter,
                // A filter change restarts at page one: page 7 of a 3-page
                // result is an empty screen and reads like a bug.
                page: next.filter !== undefined ? 1 : (next.page ?? auditLog.page),
            },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <div className="mb-4 flex flex-wrap items-end gap-3.5">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Audit log
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        Append-only, hash-chained. Failures are recorded too.
                    </p>
                </div>
                <div className="ml-auto flex items-center gap-2">
                    <Segmented
                        options={[
                            { value: 'all', label: 'All' },
                            { value: 'ok', label: 'Successes' },
                            { value: 'fail', label: 'Failures' },
                        ]}
                        value={filter}
                        onChange={(next) => go({ filter: next })}
                    />
                    <GuardedButton
                        allowed={viewer.canWipeActivity}
                        reason="Wiping the audit log takes the audit.wipe permission"
                        onClick={() => setWiping(true)}
                        tone="outline"
                    >
                        Wipe log
                    </GuardedButton>
                </div>
            </div>

            {rows.length === 0 ? (
                total === 0 && filter === 'all' ? (
                    <EmptyState
                        art="activity"
                        title="No audit entries yet"
                        description="Every reveal, change, and import will be recorded here."
                    />
                ) : (
                    <EmptyState
                        art="search"
                        title="Nothing matches this filter"
                        description={
                            filter === 'fail'
                                ? 'No failures in this log - that is the good kind of empty.'
                                : 'Every entry here is a failure. Worth a look.'
                        }
                    />
                )
            ) : (
                <>
                    <AuditTable rows={rows} />
                    <AuditPager
                        meta={auditLog}
                        onPage={(next) => go({ page: next })}
                        className="mt-3"
                    />
                </>
            )}

            {wiping && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setWiping(false)}
                    title={`Wipe ${project.name}'s audit log?`}
                    description="This is the only action in The Vault that destroys evidence. Read what it costs before you confirm."
                    consequences={[
                        `All ${total} matching entries for this project are deleted for good.`,
                        'The hash chain is re-linked around the hole, so tampering that happened BEFORE this wipe stops being provable.',
                        'A permanent marker naming you, the time and the number of rows is written and can never be wiped.',
                        'Other projects, the organization log and personal vault entries are untouched.',
                    ]}
                    confirmWord={project.name}
                    action={`${base}/activity`}
                    buttonLabel="Wipe the log"
                    onDeleted={() => setWiping(false)}
                />
            )}

            {viewer.canWipeActivity && (
                <AlertBanner
                    tone="critical"
                    title="You can destroy this log"
                    className="mt-4"
                >
                    The audit log is append-only for everyone else. It is
                    evidence, and audit.wipe makes you the exception to it.
                </AlertBanner>
            )}
        </div>
    );
}
