import { Head, router, setLayoutProps } from '@inertiajs/react';
import { FormNote, SubmitButton } from '@/components/vault';

type State =
    | 'pending'
    | 'expired'
    | 'revoked'
    | 'accepted'
    | 'unknown'
    | 'wrong-account';

type Props = {
    state: State;
    token?: string;
    organizationName?: string;
    invitedEmail?: string;
    signedInEmail?: string;
    invitedBy?: string;
    expiresAt?: string;
};

const DEAD_LINK_COPY: Partial<
    Record<State, { title: string; description: string }>
> = {
    expired: {
        title: 'This invitation has expired',
        description: 'Ask whoever invited you to send a new one.',
    },
    revoked: {
        title: 'This invitation was cancelled',
        description: 'Ask whoever invited you to send a new one.',
    },
    accepted: {
        title: 'This invitation has already been used',
        description: 'Sign in with your account instead.',
    },
    unknown: {
        title: 'We don’t recognise this link',
        description: 'Check that you copied the whole thing from your email.',
    },
};

export default function Invitation({
    state,
    token,
    organizationName,
    invitedEmail,
    signedInEmail,
    invitedBy,
    expiresAt,
}: Props) {
    const dead = DEAD_LINK_COPY[state];

    // The heading changes with the state of the link, so it is set here rather
    // than declared statically like every other page in this folder.
    setLayoutProps(
        dead ?? {
            title:
                state === 'wrong-account'
                    ? 'Wrong account'
                    : `You’ve been invited to ${organizationName}`,
            description:
                state === 'wrong-account'
                    ? `You’re signed in as ${signedInEmail}, but this invitation is for ${invitedEmail}. Sign out first, then open the link again.`
                    : `${invitedBy} invited ${invitedEmail}. You’ll set a password and turn on two-factor authentication before you get in.`,
            wide: true,
        },
    );

    return (
        <>
            <Head title="Invitation" />

            {state === 'pending' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(`/invitations/${token}/accept`);
                    }}
                >
                    <SubmitButton className="mt-0">
                        Accept the invitation
                    </SubmitButton>

                    {expiresAt && (
                        <FormNote>
                            Expires {new Date(expiresAt).toLocaleDateString()}
                        </FormNote>
                    )}
                </form>
            )}
        </>
    );
}

Invitation.layout = { wide: true };
