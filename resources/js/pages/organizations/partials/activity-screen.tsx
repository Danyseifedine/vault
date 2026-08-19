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
import type { ActivityPage, OrganizationSummary } from '../types';

/**
 * The organization's activity, a page at a time.
 *
 * Paging and the success/failure filter both live in the URL and are resolved
 * server-side: this is the only unbounded table in the product, and filtering
 * one page in the browser would hide the failures sitting on page two, which
 * is the opposite of what an audit screen is for.
 *
 * The Wipe control is the one place the append-only rule bends. It is shown
 * locked rather than hidden, because a power that destroys evidence should not
 * be a surprise to the people it can be used on.
 */
export function ActivityScreen({
    organization,
    auditLog,
}: {
    organization: OrganizationSummary;
    auditLog: ActivityPage;
}) {
    const [wiping, setWiping] = useState(false);

    const { rows, page, total, filter } = auditLog;

    /** Paging and filtering are URL state, so a page can be linked and shared. */
    const go = (next: { page?: number; filter?: string }) =>
        router.get(
            `/orgs/${organization.slug}`,
            {
                screen: 'activity',
                filter: next.filter ?? filter,
                // Changing the filter restarts at page one: page 7 of a
                // 3-page result is an empty screen and looks like a bug.
                page: next.filter !== undefined ? 1 : (next.page ?? page),
            },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <div className="w-full px-4 pt-[18px] pb-12 sm:px-7 sm:pt-[22px]">
            <div className="mb-4 flex flex-wrap items-end gap-3.5">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Org activity
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        Rolled up across projects. Failures included.
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
                        allowed={organization.canWipeActivity}
                        reason="Wiping the activity log takes the audit.wipe permission"
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
                        description="Every reveal, change, and invite will be recorded here."
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
                    title={`Wipe ${organization.name}'s activity log?`}
                    description="This is the only action in The Vault that destroys evidence. Read what it costs before you confirm."
                    consequences={[
                        `All ${total} matching entries for this organization are deleted for good.`,
                        'The hash chain is re-linked around the hole, so tampering that happened BEFORE this wipe stops being provable.',
                        'A permanent marker naming you, the time and the number of rows is written and can never be wiped.',
                        'Other organizations and personal vault entries are untouched.',
                    ]}
                    confirmWord={organization.name}
                    action={`/orgs/${organization.slug}/activity`}
                    buttonLabel="Wipe the log"
                    onDeleted={() => setWiping(false)}
                />
            )}

            {organization.canWipeActivity && (
                <AlertBanner
                    tone="critical"
                    title="You can destroy this log"
                    className="mt-4"
                >
                    The activity log is append-only for everyone else. It is
                    evidence, and audit.wipe makes you the exception to it.
                </AlertBanner>
            )}
        </div>
    );
}
