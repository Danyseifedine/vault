import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { FormAlert, FormNote, Field, SubmitButton } from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { forgotPasswordSchema } from '@/lib/validation/auth';
import type { ForgotPasswordValues } from '@/lib/validation/auth';
import { login } from '@/routes';
import { email as sendResetLink } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const form = useForm<ForgotPasswordValues>({
        resolver: zodResolver(forgotPasswordSchema),
        mode: 'onChange',
        defaultValues: { email: '' },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        sendResetLink.url(),
    );

    return (
        <form onSubmit={submit}>
            <Head title="Forgot password" />

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

            {status && <FormAlert tone="pub">{status}</FormAlert>}
            {serverError && <FormAlert>{serverError}</FormAlert>}

            <SubmitButton
                busy={busy}
                disabled={!form.formState.isValid}
                busyLabel="Sending…"
                data-test="email-password-reset-link-button"
            >
                Email a reset link
            </SubmitButton>

            <FormNote>
                Remembered it?{' '}
                <Link href={login()} className="text-primary hover:text-fg">
                    Back to sign in
                </Link>
            </FormNote>
        </form>
    );
}

ForgotPassword.layout = {
    title: 'Forgot password',
    description: 'We will email you a link to set a new one.',
};
