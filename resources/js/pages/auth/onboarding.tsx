import { Head, setLayoutProps } from '@inertiajs/react';
import {
    PasswordStep,
    ProfileStep,
    TwoFactorStep,
} from './partials/onboarding-steps';

type Step = 'password' | 'two-factor' | 'profile';

type Props = {
    step: Step;
    email: string;
    name: string | null;
    twoFactor: { qr: string; secret: string } | null;
};

const STEPS: Step[] = ['password', 'two-factor', 'profile'];

const COPY: Record<Step, { title: string; description: string }> = {
    password: {
        title: 'Set your password',
        description:
            'You will also need this password to reveal critical secrets, so choose one you can type.',
    },
    'two-factor': {
        title: 'Scan this with your authenticator',
        description:
            'Two-factor authentication is required for every account, and there are no recovery codes - so keep the app you scan with.',
    },
    profile: {
        title: 'Tell us who you are',
        description:
            'Your name and job title are what the audit log shows, so the team knows who to ask about which keys.',
    },
};

/**
 * The server decides which step is outstanding - the stepper only renders it.
 * That is what makes the flow impossible to skip: reloading, going back, or
 * posting out of order all land on whatever is still owed.
 */
export default function Onboarding({ step, email, name, twoFactor }: Props) {
    const currentIndex = STEPS.indexOf(step);

    setLayoutProps({ ...COPY[step], wide: true });

    return (
        <>
            <Head title="Finish setting up" />

            <div className="-mt-2 mb-4">
                <div className="mb-2 flex gap-1.5">
                    {STEPS.map((each, index) => (
                        <span
                            key={each}
                            className={`h-1 flex-1 rounded-full ${index <= currentIndex ? 'bg-primary' : 'bg-panel-3'}`}
                        />
                    ))}
                </div>
                <div className="font-mono text-[11px] text-fg-3">
                    Step {currentIndex + 1} of {STEPS.length} · {email}
                </div>
            </div>

            {step === 'password' && <PasswordStep />}
            {step === 'two-factor' && twoFactor && (
                <TwoFactorStep qr={twoFactor.qr} secret={twoFactor.secret} />
            )}
            {step === 'profile' && <ProfileStep name={name} />}
        </>
    );
}

Onboarding.layout = { wide: true };
