import { router } from '@inertiajs/react';
import { MailPlus, Users } from 'lucide-react';
import { useState } from 'react';
import {
    DestructiveDialog,
    EmptyState,
    InitialsAvatar,
} from '@/components/vault';
import { flash } from '@/lib/visit';
import type { OrganizationPageProps } from '../types';

type Props = OrganizationPageProps;

/** The overview's right rail: who is here, and who is on their way in. */
export function InvitesCard({
    invites,
    orgSlug,
}: {
    invites: Props['invites'];
    orgSlug: string;
}) {
    // Revoking deletes the reserved account too, so it asks in a dialog that
    // says exactly that - a browser confirm() cannot.
    const [revoking, setRevoking] = useState<Props['invites'][number] | null>(
        null,
    );

    // One in-flight resend at a time per invite, so a double-click cannot send
    // the same person two emails.
    const [resending, setResending] = useState<number | null>(null);

    const resend = (invite: Props['invites'][number]) => {
        if (resending !== null) {
            return;
        }

        router.post(`/orgs/${orgSlug}/invitations/${invite.id}/resend`, {}, {
            ...flash(`Invite resent to ${invite.email}`),
            onStart: () => setResending(invite.id),
            onFinish: () => setResending(null),
        });
    };

    return (
        <div className="overflow-hidden rounded-xl border border-line bg-panel">
            <div className="flex items-center border-b border-line px-[15px] py-3">
                <div className="text-[13px] font-semibold">Pending invites</div>
                {invites.length > 0 && (
                    <span className="ml-auto rounded-full bg-sens-soft px-2 py-0.5 text-[10.5px] text-sens">
                        {invites.length}
                    </span>
                )}
            </div>
            {invites.length === 0 ? (
                <EmptyState
                    className="m-[15px] border-none"
                    icon={<MailPlus className="size-4" strokeWidth={1.75} />}
                    title="No pending invites"
                    description="Invite a teammate and it will wait here until they accept."
                />
            ) : (
                invites.map((i) => (
                    <div
                        key={i.id}
                        className="border-b border-line px-[15px] py-[11px]"
                    >
                        <div className="flex items-center gap-2">
                            <span className="truncate text-[12.5px]">
                                {i.email}
                            </span>
                            <span className="ml-auto rounded-full bg-panel-3 px-2 py-0.5 text-[10.5px] text-fg-3">
                                {i.grants} grant{i.grants === 1 ? '' : 's'}
                            </span>
                        </div>
                        <div className="mt-1.5 flex items-center gap-2">
                            <span className="text-[11px] text-fg-3">
                                {i.expiresLabel}
                            </span>
                            <span className="ml-auto flex gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => resend(i)}
                                    disabled={resending === i.id}
                                    className="h-[23px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {resending === i.id ? 'Resending…' : 'Resend'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setRevoking(i)}
                                    className="h-[23px] cursor-pointer rounded-md border border-crit-soft bg-crit-soft px-2 text-[10.5px] text-crit transition-colors hover:bg-crit hover:text-crit-foreground"
                                >
                                    Revoke
                                </button>
                            </span>
                        </div>
                    </div>
                ))
            )}

            {revoking && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setRevoking(null)}
                    title={`Revoke the invite for ${revoking.email}?`}
                    description="They have not accepted yet, so nothing of theirs exists except the reservation."
                    consequences={[
                        'The invite link stops working immediately.',
                        'Their reserved account and its pre-granted access are deleted.',
                    ]}
                    action={`/orgs/${orgSlug}/invitations/${revoking.id}`}
                    buttonLabel="Revoke invite"
                    successMessage="Invite revoked · logged"
                    onDeleted={() => setRevoking(null)}
                />
            )}
        </div>
    );
}

export function MembersCard({
    members,
    onManage,
}: {
    members: Props['members'];
    onManage: () => void;
}) {
    return (
        <div className="overflow-hidden rounded-xl border border-line bg-panel">
            <div className="flex items-center border-b border-line px-[15px] py-3">
                <div className="text-[13px] font-semibold">Members</div>
                {members.length > 0 && (
                    <button
                        type="button"
                        onClick={onManage}
                        className="ml-auto cursor-pointer border-0 bg-transparent text-xs text-primary hover:text-fg"
                    >
                        Manage →
                    </button>
                )}
            </div>
            {members.length === 0 ? (
                <EmptyState
                    className="m-[15px] border-none"
                    icon={<Users className="size-4" strokeWidth={1.75} />}
                    title="No members yet"
                    description="Invite teammates by email to get started."
                />
            ) : (
                members.slice(0, 5).map((m) => (
                    <div
                        key={m.email}
                        className="flex items-center gap-2.5 border-b border-line px-[15px] py-[9px]"
                    >
                        <InitialsAvatar initials={m.initials} />
                        <span className="min-w-0">
                            <span className="block text-[12.5px]">
                                {m.name}
                            </span>
                            <span className="block truncate text-[10.5px] text-fg-3">
                                {m.scope}
                            </span>
                        </span>
                    </div>
                ))
            )}
        </div>
    );
}
