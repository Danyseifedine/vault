import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    DialogActions,
    DialogShell,
    formClass,
    GrantChecklist,
} from '@/components/vault';
import type { GrantRow } from '@/lib/access';
import type { OrgMember, OrgScope } from '../types';

/**
 * The full picture of one member's access, editable: every scope, every
 * permission, the same checklist the invite uses. Submitting REPLACES their
 * set - what is unchecked is revoked, nothing is implicit.
 */
export function EditAccessDialog({
    member,
    orgSlug,
    scopes,
    onClose,
}: {
    member: OrgMember;
    orgSlug: string;
    scopes: OrgScope[];
    onClose: () => void;
}) {
    const [grants, setGrants] = useState<GrantRow[]>(member.grants);
    const [busy, setBusy] = useState(false);
    const [serverError, setServerError] = useState('');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setServerError('');

        router.put(
            `/orgs/${orgSlug}/members/${member.id}/grants`,
            { grants },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Access updated for ${member.email}`);
                    onClose();
                },
                onError: (errors) => {
                    setServerError(
                        Object.values(errors)[0] ?? 'That change was refused.',
                    );
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <DialogShell
            open
            onOpenChange={(next) => !next && onClose()}
            title={`Access for ${member.name}`}
            description="What is unchecked when you save is revoked - the checklist is the whole truth."
            wide={true}
        >
            <form onSubmit={submit} className={formClass}>
                <GrantChecklist
                    scopes={scopes}
                    value={grants}
                    onChange={setGrants}
                />

                <div className="text-[11.5px] text-fg-3">
                    {grants.length === 0
                        ? 'No grants - they stay a member who can do nothing.'
                        : `${grants.length} grant${grants.length === 1 ? '' : 's'}.`}
                </div>

                {serverError && (
                    <div className="rounded-[9px] border border-crit-soft bg-crit-soft px-3 py-2.5 text-xs font-semibold text-crit">
                        {serverError}
                    </div>
                )}

                <DialogActions
                    onCancel={onClose}
                    busy={busy}
                    label="Save access"
                    busyLabel="Saving…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}
