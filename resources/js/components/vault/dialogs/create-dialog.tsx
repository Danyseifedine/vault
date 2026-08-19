import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { projectSchema } from '@/lib/validation/vault';
import type { ProjectValues } from '@/lib/validation/vault';
import { Field, FormAlert } from '../form/form';
import { DialogActions } from './dialog-actions';

/**
 * "Give it a name" - the shape organizations and projects both need.
 *
 * The fields are the same ones the sign-in screen uses, down to the red wash a
 * refused input gets: a form should not change its manners because it moved
 * from the front door into the building. Validation is react-hook-form + zod
 *, matching the Form Request behind whichever action it posts to.
 */
export function CreateDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    placeholder,
    withDescription = false,
    schema = projectSchema,
    action,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    label: string;
    placeholder: string;
    withDescription?: boolean;
    /**
     * The client rules to enforce. Defaults to a project's, but a personal
     * group's name is capped at 60 rather than 120, and a client rule that is
     * looser than the server's is worse than none - it lets you type something
     * only to have it refused after the round trip.
     */
    schema?: typeof projectSchema;
    /** Where to POST. The server re-validates everything. */
    action: string;
    onCreated?: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [serverError, setServerError] = useState('');

    const form = useForm<ProjectValues>({
        resolver: zodResolver(schema),
        mode: 'onChange',
        defaultValues: { name: '', description: '' },
    });

    const close = () => {
        onOpenChange(false);
        form.reset();
        setServerError('');
    };

    const submit = form.handleSubmit((values) => {
        setBusy(true);
        setServerError('');

        router.post(
            action,
            withDescription
                ? { name: values.name, description: values.description || null }
                : { name: values.name },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`${values.name} created.`);
                    close();
                    onCreated?.();
                },
                onError: (errors) => {
                    if (errors.name) {
                        form.setError('name', { message: errors.name });
                    } else {
                        setServerError(
                            Object.values(errors)[0] ??
                                'That could not be created.',
                        );
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
        >
            <DialogContent className="w-[400px] max-w-[calc(100vw-2rem)] border-line-2 bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        {title}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        {description}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="p-[17px] pt-1">
                    <Field
                        id="create-name"
                        label={label}
                        required
                        placeholder={placeholder}
                        autoFocus
                        error={form.formState.errors.name?.message}
                        {...form.register('name')}
                    />

                    {withDescription && (
                        <Field
                            id="create-note"
                            label="Description"
                            aside={
                                <span className="text-[11px] text-fg-3">
                                    optional
                                </span>
                            }
                            placeholder="What it is for"
                            error={form.formState.errors.description?.message}
                            {...form.register('description')}
                        />
                    )}

                    {serverError && <FormAlert>{serverError}</FormAlert>}

                    <DialogActions
                        onCancel={close}
                        busy={busy}
                        disabled={!form.formState.isValid}
                        label="Create"
                        busyLabel="Creating…"
                        size="lg"
                    />
                </form>
            </DialogContent>
        </Dialog>
    );
}
