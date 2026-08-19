import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    DialogActions,
    DialogShell,
    Field,
    FormAlert,
    formClass,
    GrantChecklist,
} from '@/components/vault';
import type { GrantRow } from '@/lib/access';
import { inviteSchema } from '@/lib/validation/vault';
import type { OrgScope } from '../types';

type EmailOnly = { email: string };

/**
 * Inviting someone IS granting them access: the email, then the full
 * checklist of exactly what they may do - permission by permission, scope by
 * scope. No roles, no presets. The account and its grants are created the
 * moment this is sent, and lie inert until accepted.
 */
export function InviteDialog({
    open,
    onOpenChange,
    orgSlug,
    orgName,
    scopes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orgSlug: string;
    orgName: string;
    scopes: OrgScope[];
}) {
    const form = useForm<EmailOnly>({
        resolver: zodResolver(inviteSchema.pick({ email: true })),
        mode: 'onChange',
        defaultValues: { email: '' },
    });

    const [grants, setGrants] = useState<GrantRow[]>([]);
    const [busy, setBusy] = useState(false);
    const [serverError, setServerError] = useState('');

    const close = () => {
        onOpenChange(false);
        form.reset();
        setGrants([]);
        setServerError('');
    };

    const submit = form.handleSubmit((values) => {
        setBusy(true);
        setServerError('');

        router.post(
            `/orgs/${orgSlug}/invitations`,
            { email: values.email, grants },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Invitation sent to ${values.email}`);
                    close();
                },
                onError: (errors) => {
                    if (errors.email) {
                        form.setError('email', { message: errors.email });
                    } else {
                        setServerError(
                            Object.values(errors)[0] ??
                                'That invitation was refused.',
                        );
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    });

    return (
        <DialogShell
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            title={`Invite to ${orgName}`}
            description="The account and its grants are created now, and stay inert until they accept."
        >
            <form onSubmit={submit} className={formClass}>
                <Field
                    id="invite-email"
                    type="email"
                    label="Email"
                    required
                    placeholder="name@acme.co"
                    autoFocus
                    wrapperClassName="mb-0"
                    error={form.formState.errors.email?.message}
                    {...form.register('email')}
                />

                <div>
                    <div className="mb-1.5 text-xs text-fg-2">
                        Exactly what they can do
                    </div>
                    <GrantChecklist
                        scopes={scopes}
                        value={grants}
                        onChange={setGrants}
                    />
                </div>

                <div className="text-[11.5px] text-fg-3">
                    {grants.length === 0
                        ? 'No permissions selected - they will be a member who can do nothing until granted.'
                        : `${grants.length} grant${grants.length === 1 ? '' : 's'}. Nothing else is implicit.`}
                </div>

                {serverError && <FormAlert>{serverError}</FormAlert>}

                <DialogActions
                    onCancel={close}
                    busy={busy}
                    disabled={!form.formState.isValid}
                    label="Send invite"
                    busyLabel="Sending…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}
