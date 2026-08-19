import { zodResolver } from '@hookform/resolvers/zod';
import { Head } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { FormAlert, PasswordField, SubmitButton } from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { confirmPasswordSchema } from '@/lib/validation/auth';
import type { ConfirmPasswordValues } from '@/lib/validation/auth';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const form = useForm<ConfirmPasswordValues>({
        resolver: zodResolver(confirmPasswordSchema),
        mode: 'onChange',
        defaultValues: { password: '' },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        store.url(),
        {
            fallback: 'That password was not accepted.',
        },
    );

    return (
        <form onSubmit={submit}>
            <Head title="Confirm password" />

            <PasswordField
                id="password"
                label="Password"
                required
                autoComplete="current-password"
                autoFocus
                error={errorFor('password')}
                {...form.register('password')}
            />

            {serverError && <FormAlert>{serverError}</FormAlert>}

            <SubmitButton
                busy={busy}
                disabled={!form.formState.isValid}
                busyLabel="Checking…"
                data-test="confirm-password-button"
            >
                Confirm
            </SubmitButton>
        </form>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm your password',
    description:
        'You are about to change something that guards secrets. Type your password once more.',
};
