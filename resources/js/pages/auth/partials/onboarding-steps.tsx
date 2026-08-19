import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import {
    FormAlert,
    Field,
    PasswordField,
    SubmitButton,
    FieldError,
} from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { OTP_MAX_LENGTH } from '@/lib/otp';
import { cn } from '@/lib/utils';
import {
    onboardingPasswordSchema,
    onboardingProfileSchema,
    otpSchema,
} from '@/lib/validation/auth';
import type {
    OnboardingPasswordValues,
    OnboardingProfileValues,
} from '@/lib/validation/auth';
import {
    password as passwordRoute,
    profile as profileRoute,
    twoFactor as twoFactorRoute,
} from '@/routes/onboarding';

/** Step one, for a seat that was reserved by an invitation and never claimed. */
export function PasswordStep() {
    const form = useForm<OnboardingPasswordValues>({
        resolver: zodResolver(onboardingPasswordSchema),
        mode: 'onChange',
        defaultValues: { password: '', password_confirmation: '' },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        passwordRoute.url(),
    );

    return (
        <form onSubmit={submit}>
            <PasswordField
                id="password"
                label="Password"
                required
                autoComplete="new-password"
                autoFocus
                error={errorFor('password')}
                {...form.register('password')}
            />
            <PasswordField
                id="password_confirmation"
                label="Confirm password"
                required
                autoComplete="new-password"
                error={errorFor('password_confirmation')}
                {...form.register('password_confirmation')}
            />

            {serverError && <FormAlert>{serverError}</FormAlert>}

            <SubmitButton
                busy={busy}
                disabled={!form.formState.isValid}
                busyLabel="Saving…"
            >
                Set password
            </SubmitButton>
        </form>
    );
}

/**
 * Step two: scan, then prove it. The secret is shown as well as encoded,
 * because some authenticator apps cannot use a camera - and because with no
 * recovery codes, this is the only chance to keep a copy anywhere.
 */
export function TwoFactorStep({ qr, secret }: { qr: string; secret: string }) {
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [copied, setCopied] = useState(false);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                setError('');
                setBusy(true);

                router.post(
                    twoFactorRoute.url(),
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
            <div className="mb-4 flex justify-center">
                <div
                    className="rounded-xl bg-white p-3 [&_svg]:size-40"
                    // Fortify renders the QR itself; it is our own markup, not user input.
                    dangerouslySetInnerHTML={{ __html: qr }}
                />
            </div>

            <div className="mb-4">
                <div className="mb-1.5 text-xs text-fg-2">
                    Can’t scan? Type this key instead
                </div>
                <button
                    type="button"
                    onClick={() => {
                        navigator.clipboard.writeText(secret);
                        setCopied(true);
                    }}
                    className="flex w-full cursor-pointer items-center gap-2 rounded-[9px] border border-line-2 bg-panel-2 px-3 py-2 text-left font-mono text-[11.5px] break-all text-fg-2 transition-colors hover:bg-panel-3"
                >
                    {secret}
                    <span className="ml-auto shrink-0 text-[10.5px] text-fg-3">
                        {copied ? 'Copied' : 'Copy'}
                    </span>
                </button>
            </div>

            <div className="mb-1.5 text-xs text-fg-2">
                Enter the six-digit code it shows
            </div>
            <div className="flex flex-col items-center gap-3">
                <InputOTP
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
                Turn on two-factor
            </SubmitButton>
        </form>
    );
}

const STACK = [
    'Laravel',
    'React',
    'React Native',
    'Vue',
    'Node',
    'Python',
    'Go',
    'Flutter',
    'DevOps',
    'Design',
];

/** Step three: who you are, so the audit log reads like people and not ids. */
export function ProfileStep({ name }: { name: string | null }) {
    const form = useForm<OnboardingProfileValues>({
        resolver: zodResolver(onboardingProfileSchema),
        mode: 'onChange',
        defaultValues: { name: name ?? '', job_title: '', stack: [] },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        profileRoute.url(),
    );
    const chosen = form.watch('stack');

    const toggle = (item: string) =>
        form.setValue(
            'stack',
            chosen.includes(item)
                ? chosen.filter((s) => s !== item)
                : [...chosen, item],
        );

    return (
        <form onSubmit={submit}>
            <Field
                id="name"
                label="Full name"
                required
                placeholder="Dany Seifeddine"
                autoComplete="name"
                autoFocus
                error={errorFor('name')}
                {...form.register('name')}
            />
            <Field
                id="job_title"
                label="Job title"
                required
                placeholder="Backend Engineer"
                autoComplete="organization-title"
                error={errorFor('job_title')}
                {...form.register('job_title')}
            />

            <div className="mb-[13px]">
                <div className="mb-1.5 text-xs text-fg-2">
                    Stack <span className="text-fg-3">· optional</span>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {STACK.map((item) => (
                        <button
                            key={item}
                            type="button"
                            onClick={() => toggle(item)}
                            className={cn(
                                'cursor-pointer rounded-full border px-2.5 py-1 text-[11.5px] transition-colors',
                                chosen.includes(item)
                                    ? 'border-primary bg-primary-soft text-primary'
                                    : 'border-line-2 bg-transparent text-fg-2 hover:bg-panel-2',
                            )}
                        >
                            {item}
                        </button>
                    ))}
                </div>
            </div>

            {serverError && <FormAlert>{serverError}</FormAlert>}

            <SubmitButton
                busy={busy}
                disabled={!form.formState.isValid}
                busyLabel="Finishing…"
            >
                Finish setup
            </SubmitButton>
        </form>
    );
}
