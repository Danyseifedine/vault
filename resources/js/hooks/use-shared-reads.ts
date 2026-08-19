import { useState } from 'react';
import type { PreviewPayload } from '@/components/vault';
import { jsonPostHeaders } from '@/lib/csrf';
import { revealMessage, useReveal } from './use-reveal';

/**
 * Reading things back out of the organization's shared vault.
 *
 * Everything here costs a PIN - `shared.reveal` plus four digits, every time,
 * with no policy that can waive it the way an environment can for a variable.
 * That single rule is why this hook exists: a personal file downloads from a
 * plain link because it is already yours, while a shared one has to collect a
 * PIN first, so all three reads share one dialog and one piece of state.
 *
 * A secret comes back as JSON and is shown in its row. A file cannot: a
 * keystore is not UTF-8, so it either streams (download) or comes back as a
 * bounded look (preview). Neither is ever a page prop - the plaintext lives
 * as long as the row is open or the save takes, and no longer.
 */

/** What the PIN is about to be spent on. */
export type ReadIntent = 'reveal' | 'preview' | 'download';

/** The least an item needs for any of this. */
export interface ReadableItem {
    id: number;
    name: string;
    type: 'secret' | 'file';
}

export function useSharedReads(base: string) {
    const {
        reveal,
        reset: resetReveal,
        busy: revealBusy,
        error: revealError,
    } = useReveal();

    const [busy, setBusy] = useState(false);
    const [fileError, setFileError] = useState<string | undefined>();

    // Which item the PIN dialog is open for, and why. Null means closed.
    const [asking, setAsking] = useState<{
        item: ReadableItem;
        intent: ReadIntent;
    } | null>(null);

    const [shown, setShown] = useState<{ id: number; value: string } | null>(
        null,
    );
    const [preview, setPreview] = useState<{
        id: number;
        payload: PreviewPayload | null;
    } | null>(null);

    const post = (url: string, pin: string) =>
        fetch(url, {
            method: 'POST',
            headers: jsonPostHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify({ pin }),
        });

    /** Turns a refused response into something a person can read. */
    const refusalOf = async (response: Response): Promise<string> => {
        const body = await response.json().catch(() => ({}));
        const reason: string =
            body.reason ?? Object.keys(body.errors ?? {})[0] ?? 'denied';

        return revealMessage(
            reason,
            body.errors?.pin?.[0] ?? 'That file could not be read.',
        );
    };

    const ask = (item: ReadableItem, intent: ReadIntent) => {
        resetReveal();
        setFileError(undefined);
        setAsking({ item, intent });
    };

    const close = () => {
        setAsking(null);
        resetReveal();
        setFileError(undefined);
    };

    /** Hides an open value or preview; otherwise opens the PIN dialog. */
    const toggle = (item: ReadableItem, intent: 'reveal' | 'preview') => {
        if (intent === 'reveal' && shown?.id === item.id) {
            setShown(null);
            resetReveal();

            return;
        }

        if (intent === 'preview' && preview?.id === item.id) {
            setPreview(null);

            return;
        }

        ask(item, intent);
    };

    const forget = (id: number) => {
        setShown((current) => (current?.id === id ? null : current));
        setPreview((current) => (current?.id === id ? null : current));
    };

    /**
     * Spends the PIN on whatever was asked for. Returns a message to toast on
     * success; the dialog stays open with an error otherwise, so a mistyped
     * PIN can be corrected without starting again.
     */
    const submit = async (pin: string): Promise<string | null> => {
        if (asking === null) {
            return null;
        }

        const { item, intent } = asking;
        const url = `${base}/shared/${item.id}`;

        if (intent === 'reveal') {
            const outcome = await reveal(`${url}/reveal`, { pin });

            if (!outcome.granted) {
                return null;
            }

            setShown({ id: item.id, value: outcome.value });
            setAsking(null);

            return 'Revealed · logged';
        }

        setBusy(true);

        try {
            const response = await post(
                `${url}/${intent === 'download' ? 'download' : 'preview'}`,
                pin,
            );

            if (!response.ok) {
                setFileError(await refusalOf(response));

                return null;
            }

            if (intent === 'download') {
                await save(response, item.name);
                setAsking(null);

                return 'Downloaded · logged';
            }

            const body = await response.json();

            setPreview({
                id: item.id,
                payload:
                    body.kind === 'image'
                        ? { kind: 'image', src: body.dataUri }
                        : body,
            });
            setAsking(null);

            return 'Opened · logged';
        } catch {
            setFileError('Could not reach the server.');

            return null;
        } finally {
            setBusy(false);
        }
    };

    return {
        asking,
        shown,
        preview,
        busy: busy || revealBusy,
        error: fileError ?? revealError,
        ask,
        close,
        toggle,
        forget,
        submit,
    };
}

/**
 * Hands the bytes to the browser. They arrive as a blob and go straight to a
 * throwaway object URL, revoked the moment the click lands - going through
 * JSON instead would corrupt anything that is not UTF-8, which is most of
 * what belongs in a vault.
 */
async function save(response: Response, name: string): Promise<void> {
    const href = URL.createObjectURL(await response.blob());
    const anchor = document.createElement('a');

    anchor.href = href;
    anchor.download = name;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(href);
}
