import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    DialogActions,
    DialogShell,
    Dropzone,
    Field,
    FieldError,
    FilePreviewPanel,
    FormAlert,
    formClass,
    groupId,
    GroupSelectField,
    PreviewToggle,
    RequiredMark,
} from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { SHARED_FILE_MAX_KB, sharedFileSchema } from '@/lib/validation/shared';
import type { SharedFileValues } from '@/lib/validation/shared';
import type { SharedGroup } from '../types';

/**
 * Putting a team file into the shared vault.
 *
 * Its own file because it is the only dialog here that handles bytes: a
 * dropzone, a size ceiling, and a local preview so you can confirm you picked
 * the right key before it is encrypted and becomes opaque.
 */

/** Uploads a team file - a .pem, a certificate - encrypted before it hits disk. */
export function UploadSharedFileDialog({
    open,
    onOpenChange,
    groups,
    action,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: SharedGroup[];
    action: string;
}) {
    const [showing, setShowing] = useState(false);

    const form = useForm<SharedFileValues>({
        resolver: zodResolver(sharedFileSchema),
        mode: 'onChange',
        defaultValues: { name: '', description: '', shared_group_id: '' },
    });

    const close = () => {
        onOpenChange(false);
        form.reset();
        setShowing(false);
        resetErrors();
    };

    const { busy, serverError, errorFor, submit, resetErrors } = useFormSubmit(
        form,
        action,
        {
            forceFormData: true,
            preserveScroll: true,
            fallback: 'That file could not be stored.',
            transform: (values) => ({
                file: values.file,
                // Blank means "call it what the file is called" - the server
                // falls back to the original name rather than refusing.
                name: values.name || null,
                description: values.description || null,
                shared_group_id: groupId(values.shared_group_id),
            }),
            onSuccess: () => {
                toast.success('File stored, encrypted');
                close();
            },
        },
    );

    return (
        <DialogShell
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            title="Upload a shared file"
            description="Encrypted before it touches the disk - a copied storage folder is useless."
        >
            <form onSubmit={submit} className={formClass}>
                <div>
                    <div className="mb-1.5 text-xs text-fg-2">
                        File
                        <RequiredMark />
                    </div>
                    <Controller
                        control={form.control}
                        name="file"
                        render={({ field }) => (
                            <>
                                <Dropzone
                                    file={field.value ?? null}
                                    maxKb={SHARED_FILE_MAX_KB}
                                    error={Boolean(errorFor('file'))}
                                    title="Drop the key or certificate here"
                                    hint="Anything the team needs kept safe"
                                    onFile={(picked) => {
                                        field.onChange(picked ?? undefined);
                                        setShowing(false);

                                        if (picked && !form.getValues('name')) {
                                            form.setValue('name', picked.name, {
                                                shouldValidate: true,
                                            });
                                        }
                                    }}
                                    onReject={(reason) =>
                                        form.setError('file', {
                                            message: reason,
                                        })
                                    }
                                >
                                    <PreviewToggle
                                        open={showing}
                                        onToggle={() =>
                                            setShowing((current) => !current)
                                        }
                                    />
                                </Dropzone>
                                {field.value && showing && (
                                    <FilePreviewPanel
                                        file={field.value}
                                        className="mt-2"
                                    />
                                )}
                            </>
                        )}
                    />
                    <FieldError message={errorFor('file')} />
                </div>
                <Field
                    id="shared-file-name"
                    label="Name"
                    aside={
                        <span className="text-[11px] text-fg-3">
                            defaults to the filename
                        </span>
                    }
                    wrapperClassName="mb-0"
                    error={errorFor('name')}
                    {...form.register('name')}
                />
                <Field
                    id="shared-file-note"
                    label="Note"
                    aside={
                        <span className="text-[11px] text-fg-3">optional</span>
                    }
                    wrapperClassName="mb-0"
                    error={errorFor('description')}
                    {...form.register('description')}
                />
                <GroupSelectField
                    name="shared_group_id"
                    groups={groups}
                    control={form.control}
                />
                {serverError && <FormAlert>{serverError}</FormAlert>}
                <DialogActions
                    onCancel={close}
                    busy={busy}
                    disabled={!form.formState.isValid}
                    label="Upload"
                    busyLabel="Uploading…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}
