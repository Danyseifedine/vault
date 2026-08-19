import { zodResolver } from '@hookform/resolvers/zod';
import { Head } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { FormAlert, PasswordField, SubmitButton } from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { resetPasswordSchema } from '@/lib/validation/auth';
import type { ResetPasswordValues } from '@/lib/validation/auth';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    const form = useForm<ResetPasswordValues>({
        resolver: zodResolver(resetPasswordSchema),
        mode: 'onChange',
        defaultValues: { password: '', password_confirmation: '' },
    });

    // The token and the address are the credential here: they come from the
    // emailed link, never from anything the person can retype.
    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        update.url(),
        {
            extra: { token, email },
        },
    );

    return (
        <form onSubmit={submit}>
            <Head title="Reset password" />

            <div className="mb-[13px]">
                <div className="mb-1.5 text-xs text-fg-2">Account</div>
                <div className="flex h-[38px] items-center rounded-[9px] border border-line-2 bg-panel-3 px-3 font-mono text-[12.5px] text-fg-2">
                    {email}
                </div>
            </div>

            <PasswordField
                id="password"
                label="New password"
                required
                autoComplete="new-password"
                autoFocus
                passwordrules={passwordRules}
                error={errorFor('password')}
                {...form.register('password')}
            />

            <PasswordField
                id="password_confirmation"
                label="Confirm new password"
                required
                autoComplete="new-password"
                passwordrules={passwordRules}
                error={errorFor('password_confirmation')}
                {...form.register('password_confirmation')}
            />

            {serverError && <FormAlert>{serverError}</FormAlert>}

            <SubmitButton
                busy={busy}
                disabled={!form.formState.isValid}
                busyLabel="Saving…"
                data-test="reset-password-button"
            >
                Set new password
            </SubmitButton>
        </form>
    );
}

ResetPassword.layout = {
    title: 'Set a new password',
    description:
        'You will need this password again to reveal critical secrets, so choose one you can type.',
};
