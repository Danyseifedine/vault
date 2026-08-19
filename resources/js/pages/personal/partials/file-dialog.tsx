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
import {
    PERSONAL_FILE_MAX_KB,
    personalFileSchema,
} from '@/lib/validation/personal';
import type { PersonalFileValues } from '@/lib/validation/personal';
import type { PersonalGroup } from '../types';

/**
 * Putting a file into the personal vault.
 *
 * Its own file because it is the only dialog here that handles bytes: a
 * dropzone, a size ceiling, and a local preview so you can confirm you picked
 * the right key before it is encrypted and becomes opaque. Mirrors
 * organizations/partials/shared-file-dialog.tsx, which does the same job for
 * the team vault.
 */

/** Uploads a file, encrypted BEFORE it reaches the disk. */
export function UploadFileDialog({
    open,
    onOpenChange,
    groups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: PersonalGroup[];
}) {
    const [showing, setShowing] = useState(false);

    const form = useForm<PersonalFileValues>({
        resolver: zodResolver(personalFileSchema),
        mode: 'onChange',
        defaultValues: { name: '', description: '', personal_group_id: '' },
    });

    const close = () => {
        onOpenChange(false);
        form.reset();
        setShowing(false);
        resetErrors();
    };

    const { busy, serverError, errorFor, submit, resetErrors } = useFormSubmit(
        form,
        '/personal/files',
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
                personal_group_id: groupId(values.personal_group_id),
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
            title="Upload a file"
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
                                    maxKb={PERSONAL_FILE_MAX_KB}
                                    error={Boolean(errorFor('file'))}
                                    title="Drop your file here"
                                    hint="Anything you need kept private"
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
                    id="file-name"
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
                    id="file-note"
                    label="Note"
                    aside={
                        <span className="text-[11px] text-fg-3">optional</span>
                    }
                    placeholder="Where it came from, what it opens"
                    wrapperClassName="mb-0"
                    error={errorFor('description')}
                    {...form.register('description')}
                />
                <GroupSelectField
                    name="personal_group_id"
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
