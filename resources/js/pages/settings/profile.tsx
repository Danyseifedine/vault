import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import {
    DestructiveDialog,
    Field,
    FormAlert,
    SubmitButton,
    fieldClass,
} from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import AccountLayout, { AccountSection } from '@/layouts/account-layout';
import { cn } from '@/lib/utils';
import { profileSchema } from '@/lib/validation/auth';
import type { ProfileValues } from '@/lib/validation/auth';
import { send } from '@/routes/verification';

type Props = {
    name: string;
    email: string;
    mustVerifyEmail: boolean;
    status?: string;
    emailVerified: boolean;
};

export default function Profile({
    name,
    email,
    mustVerifyEmail,
    status,
    emailVerified,
}: Props) {
    const [deleting, setDeleting] = useState(false);

    const form = useForm<ProfileValues>({
        resolver: zodResolver(profileSchema),
        mode: 'onChange',
        defaultValues: { name },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        ProfileController.update.url(),
    );

    return (
        <AccountSection
            title="Profile"
            description="Your name is what the audit log prints next to everything you do, so keep it the one your team would recognise."
        >
            <Head title="Profile settings" />

            <form
                onSubmit={submit}
                className="rounded-[14px] border border-line bg-panel p-5"
            >
                <Field
                    id="name"
                    label="Name"
                    required
                    autoComplete="name"
                    defaultValue={name}
                    error={errorFor('name')}
                    {...form.register('name')}
                />

                {/*
                    Read-only, and refused server-side too. This address is the
                    account's identity: every invitation, grant and audit row was
                    written against it.
                */}
                <div className="mb-[13px]">
                    <div className="mb-1.5 flex items-center">
                        <span className="text-xs text-fg-2">Email address</span>
                        <span className="ml-auto text-[11px] text-fg-3">
                            cannot be changed
                        </span>
                    </div>
                    <div
                        className={cn(
                            fieldClass,
                            'flex cursor-not-allowed items-center bg-panel-3 font-mono text-[12.5px] text-fg-2',
                        )}
                    >
                        {email}
                    </div>
                    {mustVerifyEmail && !emailVerified && (
                        <div className="mt-1.5 text-[11.5px] text-fg-3">
                            This address is unverified.{' '}
                            <Link
                                href={send()}
                                as="button"
                                className="cursor-pointer text-primary hover:text-fg"
                            >
                                Send the link again
                            </Link>
                            {status === 'verification-link-sent' && (
                                <span className="ml-1 text-pub">Sent.</span>
                            )}
                        </div>
                    )}
                </div>

                {serverError && <FormAlert>{serverError}</FormAlert>}

                <SubmitButton
                    busy={busy}
                    disabled={!form.formState.isValid}
                    busyLabel="Saving…"
                    data-test="update-profile-button"
                >
                    Save
                </SubmitButton>
            </form>

            <div className="mt-3.5 rounded-[14px] border border-crit-soft bg-panel p-5">
                <div className="mb-1 text-[13px] font-semibold text-crit">
                    Delete this account
                </div>
                <p className="mb-3.5 text-[12.5px] text-pretty text-fg-2">
                    Your personal vault goes with it. What you did inside an
                    organization stays in its audit log: that record is not
                    yours to erase.
                </p>
                <button
                    type="button"
                    onClick={() => setDeleting(true)}
                    data-test="delete-user-button"
                    className="h-[34px] cursor-pointer rounded-[9px] border border-crit-soft bg-crit-soft px-3 text-[12.5px] font-semibold text-crit transition-colors hover:bg-crit hover:text-crit-foreground"
                >
                    Delete account
                </button>
            </div>

            <DestructiveDialog
                open={deleting}
                onOpenChange={setDeleting}
                title="Delete your account?"
                description="This cannot be undone. Sign-in stops working immediately."
                confirmWord={email}
                consequences={[
                    'Every personal secret and uploaded file you own is destroyed.',
                    'You are removed from every organization you belong to.',
                    'The audit log keeps what you did: it is append-only.',
                ]}
                action={ProfileController.destroy.url()}
                buttonLabel="Delete my account"
                requirePassword
            />
        </AccountSection>
    );
}

Profile.layout = (page: React.ReactNode) => (
    <AccountLayout active="profile">{page}</AccountLayout>
);
