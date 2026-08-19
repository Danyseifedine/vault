import { useState } from 'react';
import { jsonPostHeaders } from '@/lib/csrf';

type Outcome =
    | { granted: true; value: string }
    | {
          granted: false;
          reason: string;
          message: string;
          attemptsRemaining?: number | null;
      };

const MESSAGES: Record<string, string> = {
    denied: 'You do not have permission to reveal this value.',
    pin_required: 'Enter your reveal PIN.',
    pin_incorrect: 'That PIN is not correct.',
    password_required:
        'This value is critical - your account password is required too.',
    password_incorrect: 'That password is not correct.',
    locked: 'Too many attempts. Try again later.',
    file_item: 'A file is read by opening or downloading it, not like a value.',
};

/**
 * How a refusal reads to a person. Exported because the shared vault's file
 * endpoints answer with the same reasons and must phrase them the same way -
 * two wordings for one refusal is how a UI starts lying.
 */
export const revealMessage = (reason: string, fallback: string): string =>
    MESSAGES[reason] ?? fallback;

/**
 * Asks the server to reveal one value.
 *
 * This is the only place the frontend ever holds a plaintext secret, and it
 * holds it in local state for exactly as long as the dialog is open - never in
 * a page prop, where it would survive in the history stack and partial reloads.
 * Every call is audited server-side whether it succeeds or not.
 */
export function useReveal() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [value, setValue] = useState<string | undefined>();

    const reset = () => {
        setError(undefined);
        setValue(undefined);
        setBusy(false);
    };

    const reveal = async (
        url: string,
        credentials: { pin?: string; password?: string },
    ): Promise<Outcome> => {
        setBusy(true);
        setError(undefined);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: jsonPostHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    pin: credentials.pin || null,
                    password: credentials.password || null,
                }),
            });

            const body = await response.json();

            if (response.ok) {
                setValue(body.value);

                return { granted: true, value: body.value };
            }

            // Validation failures come back keyed by field; policy refusals
            // come back as a reason we can phrase properly.
            const reason: string =
                body.reason ?? Object.keys(body.errors ?? {})[0] ?? 'denied';
            const message = revealMessage(
                reason,
                body.errors?.pin?.[0] ?? 'That reveal was refused.',
            );

            setError(message);

            return {
                granted: false,
                reason,
                message,
                attemptsRemaining: body.attempts_remaining,
            };
        } catch {
            const message = 'Could not reach the server.';
            setError(message);

            return { granted: false, reason: 'network', message };
        } finally {
            setBusy(false);
        }
    };

    return { reveal, reset, busy, error, value };
}
