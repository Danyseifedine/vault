import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { FormAlert, FormNote, SubmitButton } from '@/components/vault';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const [busy, setBusy] = useState(false);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                router.post(send.url(), {}, { onFinish: () => setBusy(false) });
            }}
        >
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <FormAlert tone="pub">
                    A fresh link is on its way to your inbox.
                </FormAlert>
            )}

            <SubmitButton busy={busy} busyLabel="Sending…">
                Resend the verification email
            </SubmitButton>

            <FormNote>
                Wrong account?{' '}
                <Link
                    href={logout()}
                    as="button"
                    className="cursor-pointer text-primary hover:text-fg"
                >
                    Sign out
                </Link>
            </FormNote>
        </form>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email',
    description:
        'Click the link we just emailed you. Nothing in The Vault opens until the address is confirmed.',
};
