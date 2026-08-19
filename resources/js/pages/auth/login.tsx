import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, usePage } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import {
    FormAlert,
    FormNote,
    Field,
    PasswordField,
    SubmitButton,
} from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { loginSchema } from '@/lib/validation/auth';
import type { LoginValues } from '@/lib/validation/auth';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const { googleEnabled } = usePage().props;

    const form = useForm<LoginValues>({
        resolver: zodResolver(loginSchema),
        mode: 'onChange',
        defaultValues: { email: '', password: '', remember: false },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        store.url(),
        {
            fallback: 'Sign in failed.',
        },
    );

    return (
        <form onSubmit={submit}>
            <Head title="Sign in" />

            {googleEnabled && (
                <>
                    {/* A full page load, not an Inertia visit: the next stop is
                        Google, outside this application. */}
                    <a
                        href="/auth/google/redirect"
                        className="flex h-[38px] w-full cursor-pointer items-center justify-center gap-2.5 rounded-[9px] border border-line-2 bg-transparent text-[13px] font-medium transition-colors hover:bg-panel-2"
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 18 18"
                            aria-hidden
                        >
                            <path
                                fill="#4285F4"
                                d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.71-1.58 2.68-3.9 2.68-6.62Z"
                            />
                            <path
                                fill="#34A853"
                                d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.34A9 9 0 0 0 9 18Z"
                            />
                            <path
                                fill="#FBBC05"
                                d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z"
                            />
                            <path
                                fill="#EA4335"
                                d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z"
                            />
                        </svg>
                        Continue with Google
                    </a>

                    <div className="my-[18px] flex items-center gap-3">
                        <span className="h-px flex-1 bg-line" />
                        <span className="text-[11px] text-fg-3">or</span>
                        <span className="h-px flex-1 bg-line" />
                    </div>
                </>
            )}

            <Field
                id="email"
                type="email"
                label="Email"
                required
                placeholder="you@acme.co"
                autoComplete="email"
                autoFocus
                error={errorFor('email')}
                {...form.register('email')}
            />

            <PasswordField
                id="password"
                label="Password"
                required
                autoComplete="current-password"
                error={errorFor('password')}
                aside={
                    canResetPassword && (
                        <Link
                            href={request()}
                            className="text-xs text-primary hover:text-fg"
                        >
                            Forgot password?
                        </Link>
                    )
                }
                {...form.register('password')}
            />

            <label className="flex cursor-pointer items-center gap-2 py-1 text-xs text-fg-2">
                <input
                    type="checkbox"
                    className="accent-primary"
                    {...form.register('remember')}
                />
                Remember me
            </label>

            {status && <FormAlert tone="pub">{status}</FormAlert>}
            {serverError && <FormAlert>{serverError}</FormAlert>}

            <SubmitButton
                busy={busy}
                disabled={!form.formState.isValid}
                busyLabel="Signing in…"
                data-test="login-button"
            >
                Sign in
            </SubmitButton>

            <FormNote>Reveal PIN is set up after your first sign-in.</FormNote>
        </form>
    );
}

Login.layout = {
    title: 'Sign in',
    description: 'Use your work account. Every sign-in is audited.',
};
