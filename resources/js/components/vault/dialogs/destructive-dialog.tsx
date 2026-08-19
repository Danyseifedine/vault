import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FormAlert, PasswordField } from '../form/form';
import { DialogActions } from './dialog-actions';

/**
 * The confirmation for something that cannot be undone.
 *
 * It lists what goes, in plain words, since the whole point is that you know
 * before you press it. For the largest acts it also asks you to type the name:
 * a dialog dismissed by reflex is not a confirmation. Smaller deletions skip
 * the typing and keep the dialog, which is still worth more than a browser
 * confirm() that cannot say what is about to be lost.
 */
export function DestructiveDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmWord,
    consequences,
    action,
    method = 'delete',
    successMessage,
    buttonLabel = 'Delete forever',
    requirePassword = false,
    onDeleted,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    /** When given, must be typed exactly before the button unlocks. */
    confirmWord?: string;
    consequences: string[];
    /** Where to DELETE. The server refuses independently. */
    action: string;
    /** DELETE unless the endpoint is a POST (a rollback, a revoke-by-post). */
    method?: 'delete' | 'post';
    /** Shown on success instead of the default 'Deleted.'. */
    successMessage?: string;
    buttonLabel?: string;
    /** Also demand the account password, for deleting the account itself. */
    requirePassword?: boolean;
    onDeleted?: () => void;
}) {
    const [typed, setTyped] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const matches =
        (confirmWord === undefined || typed.trim() === confirmWord) &&
        (!requirePassword || password !== '');

    const close = () => {
        onOpenChange(false);
        setTyped('');
        setPassword('');
        setError('');
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
        >
            <DialogContent className="w-[440px] max-w-[calc(100vw-2rem)] border-crit-soft bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold text-crit">
                        {title}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        {description}
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (!matches) {
                            return;
                        }

                        setBusy(true);
                        setError('');

                        // `visit` rather than the method shorthands: DELETE and
                        // POST put their data in different argument slots.
                        router.visit(action, {
                            method,
                            data: requirePassword ? { password } : undefined,
                            preserveScroll: true,
                            onSuccess: () => {
                                toast.success(
                                    successMessage ??
                                        (confirmWord
                                            ? `${confirmWord} deleted.`
                                            : 'Deleted.'),
                                );
                                close();
                                onDeleted?.();
                            },
                            onError: (errors) =>
                                setError(
                                    Object.values(errors)[0] ??
                                        'That could not be deleted.',
                                ),
                            onFinish: () => setBusy(false),
                        });
                    }}
                    className="p-[17px] pt-3"
                >
                    <ul className="mb-4 flex flex-col gap-1.5 rounded-[9px] border border-crit-soft bg-crit-soft px-3 py-2.5 text-[11.5px] text-crit">
                        {consequences.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>

                    {confirmWord !== undefined && (
                        <Field
                            id="confirm-word"
                            label={`Type “${confirmWord}” to confirm`}
                            required
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            autoComplete="off"
                            autoFocus
                            spellCheck={false}
                        />
                    )}

                    {requirePassword && (
                        <PasswordField
                            id="confirm-password"
                            label="Your password"
                            required
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            autoComplete="current-password"
                        />
                    )}

                    {error && <FormAlert>{error}</FormAlert>}

                    <DialogActions
                        onCancel={close}
                        busy={busy}
                        disabled={!matches}
                        label={buttonLabel}
                        busyLabel="Deleting…"
                        tone="danger"
                        size="lg"
                    />
                </form>
            </DialogContent>
        </Dialog>
    );
}
