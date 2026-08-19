import { zodResolver } from '@hookform/resolvers/zod';
import { Head } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import { FormAlert, PasswordField, SubmitButton } from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import AccountLayout, { AccountSection } from '@/layouts/account-layout';
import { changePasswordSchema } from '@/lib/validation/auth';
import type { ChangePasswordValues } from '@/lib/validation/auth';

type Props = {
    passwordRules: string;
    twoFactorEnabled?: boolean;
};

export default function Security({ passwordRules, twoFactorEnabled }: Props) {
    const form = useForm<ChangePasswordValues>({
        resolver: zodResolver(changePasswordSchema),
        mode: 'onChange',
        defaultValues: {
            current_password: '',
            password: '',
            password_confirmation: '',
        },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        SecurityController.update.url(),
        {
            onSuccess: () => form.reset(),
        },
    );

    return (
        <AccountSection
            title="Security"
            description="Your password guards more than your session - revealing a critical secret asks for it again."
        >
            <Head title="Security settings" />

            <form
                onSubmit={submit}
                className="rounded-[14px] border border-line bg-panel p-5"
            >
                <div className="mb-3.5 text-[13px] font-semibold">
                    Change password
                </div>

                <PasswordField
                    id="current_password"
                    label="Current password"
                    required
                    autoComplete="current-password"
                    error={errorFor('current_password')}
                    {...form.register('current_password')}
                />

                <PasswordField
                    id="password"
                    label="New password"
                    required
                    autoComplete="new-password"
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
                    data-test="update-password-button"
                >
                    Update password
                </SubmitButton>
            </form>

            {/*
                No enable/disable controls: two-factor authentication is required
                for every account, so a "disable" button would sign you out of
                the application and straight back into the setup screen. The way
                out of a lost device is a command, not a click.
            */}
            <div className="mt-3.5 rounded-[14px] border border-line bg-panel p-5">
                <div className="mb-1 flex items-center gap-2">
                    <span className="text-[13px] font-semibold">
                        Two-factor authentication
                    </span>
                    <span className="rounded-full bg-pub-soft px-2 py-0.5 text-[10.5px] font-semibold text-pub">
                        {twoFactorEnabled ? 'On' : 'Required'}
                    </span>
                </div>
                <p className="text-[12.5px] text-pretty text-fg-2">
                    Required for every account, with no opt-out and no recovery
                    codes - a printed code that walks past two-factor is a
                    second, weaker password. If you lose the authenticator app,
                    an owner runs{' '}
                    <code className="rounded bg-panel-3 px-1 font-mono text-[11.5px]">
                        vault:reset-two-factor
                    </code>{' '}
                    on the server and you set it up again on your next sign-in.
                </p>
            </div>
        </AccountSection>
    );
}

Security.layout = (page: React.ReactNode) => (
    <AccountLayout active="security">{page}</AccountLayout>
);
