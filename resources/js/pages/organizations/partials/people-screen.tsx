import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    EmptyState,
    FieldError,
    GuardedButton,
    InitialsAvatar,
    LockedNote,
} from '@/components/vault';
import { cn } from '@/lib/utils';
import { issuePinSchema } from '@/lib/validation/vault';
import type { IssuePinValues } from '@/lib/validation/vault';
import { flash as go } from '@/lib/visit';
import type {
    OrgMember,
    OrganizationPageProps,
    OrganizationSummary,
    OrgScope,
} from '../types';
import { EditAccessDialog } from './edit-access-dialog';

/**
 * People, what each of them may do, and their reveal PINs.
 *
 * There are no roles - the "Edit access" dialog IS someone's standing here.
 * PIN controls follow the pins.manage grant, and the server refuses everyone
 * else regardless of what this screen shows.
 */
export function PeopleScreen({
    organization,
    members,
    scopes,
    query,
    onInvite,
}: {
    organization: OrganizationSummary;
    members: OrganizationPageProps['members'];
    scopes: OrgScope[];
    query: string;
    onInvite: () => void;
}) {
    const [issuing, setIssuing] = useState<OrgMember | null>(null);
    const [issuingBusy, setIssuingBusy] = useState(false);
    const [editing, setEditing] = useState<OrgMember | null>(null);

    // The credential that guards every reveal is validated like every other
    // form in the product: rhf + zod, submit locked until the schema passes.
    const form = useForm<IssuePinValues>({
        resolver: zodResolver(issuePinSchema),
        mode: 'onChange',
        defaultValues: { pin: '', label: '' },
    });

    /*
     * Read HERE, not down in the row, and do not inline these back into the
     * JSX. The PIN form is rendered inside `members.map(...)`, and React
     * Compiler memoizes that whole callback on the values it can see - `form`
     * is one stable object, so `form.formState.isValid` read inside the
     * callback is captured once and never recomputed: the Issue button would
     * stay disabled no matter what you type. Destructured out here, `isValid`
     * and `errors` become dependencies of that memo, so typing invalidates it.
     */
    const { isValid, errors } = form.formState;

    /*
     * One form serves every row, so it MUST be emptied when the row changes:
     * otherwise a PIN typed for one person is still sitting there, valid and
     * submittable, when you open somebody else's row - and issuing the wrong
     * person a reveal credential is not a mistake this screen should make easy.
     */
    const target = (member: OrgMember | null) => {
        form.reset();
        setIssuing(member);
    };

    const rows = members.filter(
        (m) =>
            !query ||
            m.name.toLowerCase().includes(query.toLowerCase()) ||
            m.email.toLowerCase().includes(query.toLowerCase()),
    );

    const issuePin = form.handleSubmit((values) => {
        if (!issuing || issuingBusy) {
            return;
        }

        router.post(
            `/orgs/${organization.slug}/pins`,
            {
                user_id: issuing.id,
                pin: values.pin,
                label: values.label.trim() || null,
            },
            {
                ...go(`PIN issued to ${issuing.email}`),
                onStart: () => setIssuingBusy(true),
                onSuccess: () => {
                    toast.success(`PIN issued to ${issuing.email}`);
                    setIssuing(null);
                    form.reset();
                },
                onFinish: () => setIssuingBusy(false),
            },
        );
    });

    const setPinStatus = (pinId: number, status: 'active' | 'blocked') =>
        router.patch(
            `/orgs/${organization.slug}/pins/${pinId}`,
            { status },
            go(
                status === 'blocked'
                    ? 'PIN blocked - reveals stop immediately'
                    : 'PIN re-activated',
            ),
        );

    return (
        <div className="w-full px-4 pt-[18px] pb-12 sm:px-7 sm:pt-[22px]">
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Members
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        No roles - each person holds exactly the permissions
                        they were granted, per scope.
                    </p>
                </div>
                <GuardedButton
                    allowed={organization.canInvite}
                    reason="Inviting takes the members.invite permission"
                    onClick={onInvite}
                    className="ml-auto"
                >
                    Invite by email
                </GuardedButton>
            </div>

            {rows.length === 0 ? (
                <EmptyState
                    title="Nobody here yet"
                    description="Invite teammates by email - accounts are created the moment you do."
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    {rows.map((member) => (
                        <div
                            key={member.email}
                            className="border-b border-line px-[15px] py-3"
                        >
                            <div className="flex items-center gap-2.5">
                                <InitialsAvatar initials={member.initials} />
                                <div className="min-w-0">
                                    <div className="truncate text-[12.5px]">
                                        {member.name}
                                    </div>
                                    <div className="truncate text-[11px] text-fg-3">
                                        {member.email}
                                    </div>
                                </div>

                                <div className="ml-auto flex items-center gap-2">
                                    <span className="text-[11px] text-fg-3">
                                        {member.scope}
                                    </span>
                                    <GuardedButton
                                        allowed={organization.canManageMembers}
                                        reason="Editing access takes members.manage"
                                        onClick={() => setEditing(member)}
                                        tone="outline"
                                    >
                                        Edit access
                                    </GuardedButton>
                                </div>
                            </div>

                            {/* PIN data is only ever SENT to a pins.manage
                                holder - for everyone else the row still shows,
                                locked, so the feature and its keeper are no
                                secret. */}
                            {!organization.canManagePins && (
                                <div className="mt-2.5 border-t border-line pt-2.5">
                                    <LockedNote>
                                        Reveal PINs - managed by holders of
                                        pins.manage
                                    </LockedNote>
                                </div>
                            )}

                            {organization.canManagePins && (
                                <div className="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-line pt-2.5">
                                    <span className="text-[11px] text-fg-3">
                                        Reveal PINs
                                    </span>
                                    {member.pins.length === 0 && (
                                        <span className="text-[11px] text-fg-3">
                                            {' '}
                                            - none issued
                                        </span>
                                    )}
                                    {member.pins.map((p) => (
                                        <span
                                            key={p.id}
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10.5px]',
                                                p.status === 'active'
                                                    ? 'border-pub-soft bg-pub-soft text-pub'
                                                    : 'border-line-2 text-fg-3',
                                            )}
                                        >
                                            {p.label ?? 'PIN'} · {p.status}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setPinStatus(
                                                        p.id,
                                                        p.status === 'active'
                                                            ? 'blocked'
                                                            : 'active',
                                                    )
                                                }
                                                className="cursor-pointer border-0 bg-transparent p-0 text-[10.5px] underline"
                                            >
                                                {p.status === 'active'
                                                    ? 'block'
                                                    : 'activate'}
                                            </button>
                                        </span>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            target(
                                                issuing?.id === member.id
                                                    ? null
                                                    : member,
                                            )
                                        }
                                        className="ml-auto h-[23px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                                    >
                                        {issuing?.id === member.id
                                            ? 'Cancel'
                                            : 'Issue PIN'}
                                    </button>
                                </div>
                            )}

                            {issuing?.id === member.id && (
                                <form onSubmit={issuePin} className="mt-2">
                                    <div className="flex items-center gap-2">
                                        <input
                                            {...form.register('pin')}
                                            inputMode="numeric"
                                            maxLength={4}
                                            placeholder="4 digits"
                                            className={cn(
                                                'h-[30px] w-[92px] rounded-lg border bg-panel-2 px-2 font-mono text-[12.5px] outline-none focus:border-primary',
                                                errors.pin
                                                    ? 'border-crit'
                                                    : 'border-line-2',
                                            )}
                                        />
                                        <input
                                            {...form.register('label')}
                                            placeholder="Label (optional)"
                                            className={cn(
                                                'h-[30px] flex-1 rounded-lg border bg-panel-2 px-2 text-[12.5px] outline-none focus:border-primary',
                                                errors.label
                                                    ? 'border-crit'
                                                    : 'border-line-2',
                                            )}
                                        />
                                        <button
                                            type="submit"
                                            disabled={!isValid || issuingBusy}
                                            className="h-[30px] cursor-pointer rounded-lg border-0 bg-primary px-3 text-[12.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {issuingBusy ? 'Issuing…' : 'Issue'}
                                        </button>
                                    </div>
                                    <FieldError
                                        message={
                                            errors.pin
                                                ?.message ??
                                            errors.label?.message
                                        }
                                    />
                                </form>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <p className="mt-2.5 text-[11.5px] text-fg-3">
                Blocking a PIN stops that person revealing immediately, without
                touching their account or their access.
            </p>

            {editing && (
                <EditAccessDialog
                    member={editing}
                    orgSlug={organization.slug}
                    scopes={scopes}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}
