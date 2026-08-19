import { Head, router } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { FormNote, SubmitButton, FieldError } from '@/components/vault';
import { OTP_MAX_LENGTH } from '@/lib/otp';
import { otpSchema } from '@/lib/validation/auth';
import { cancel } from '@/routes/two-factor';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                setError('');
                setBusy(true);

                router.post(
                    store.url(),
                    { code },
                    {
                        onError: (errors) => {
                            setError(
                                errors.code ?? 'That code was not accepted.',
                            );
                            setCode('');
                        },
                        onFinish: () => setBusy(false),
                    },
                );
            }}
        >
            <Head title="Two-factor authentication" />

            <div className="flex flex-col items-center gap-3">
                <InputOTP
                    name="code"
                    maxLength={OTP_MAX_LENGTH}
                    value={code}
                    onChange={setCode}
                    disabled={busy}
                    pattern={REGEXP_ONLY_DIGITS}
                    autoFocus
                >
                    <InputOTPGroup>
                        {Array.from({ length: OTP_MAX_LENGTH }, (_, index) => (
                            <InputOTPSlot key={index} index={index} />
                        ))}
                    </InputOTPGroup>
                </InputOTP>
                <FieldError message={error || undefined} />
            </div>

            <SubmitButton
                busy={busy}
                busyLabel="Checking…"
                disabled={!otpSchema.safeParse(code).success}
            >
                Continue
            </SubmitButton>

            {/*
                A password has been accepted but no session exists yet, so there
                is nothing to log out of - this drops the pending challenge.
            */}
            <FormNote>
                <button
                    type="button"
                    onClick={() => router.post(cancel.url())}
                    className="cursor-pointer border-0 bg-transparent p-0 text-[11.5px] text-primary hover:text-fg"
                >
                    Use a different account
                </button>
            </FormNote>
        </form>
    );
}

TwoFactorChallenge.layout = {
    title: 'Authentication code',
    description:
        'Enter the six-digit code from your authenticator app. It is the only way in - The Vault has no recovery codes.',
};
