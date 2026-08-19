import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    DialogActions,
    DialogShell,
    Field,
    FormAlert,
    formClass,
    groupId,
    GroupSelectField,
    TextareaField,
} from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { sharedItemSchema, sharedSecretSchema } from '@/lib/validation/shared';
import type {
    SharedItemValues,
    SharedSecretValues,
} from '@/lib/validation/shared';
import type { SharedGroup, SharedItem } from '../types';

/**
 * The shared vault's field dialogs: adding a team credential, and editing
 * either kind of item. Uploading a file is its own file, because bytes bring
 * a dropzone and a preview with them.
 *
 * Both validate with react-hook-form + zod and put the message under the field
 * that earned it, exactly as the personal vault's do - the two vaults
 * hold different things but ask for them the same way.
 */

/** Stores a team credential: a server password, a token, a licence key. */
export function NewSharedSecretDialog({
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
    const form = useForm<SharedSecretValues>({
        resolver: zodResolver(sharedSecretSchema),
        mode: 'onChange',
        defaultValues: {
            name: '',
            value: '',
            description: '',
            shared_group_id: '',
        },
    });

    const close = () => {
        onOpenChange(false);
        form.reset();
        resetErrors();
    };

    const { busy, serverError, errorFor, submit, resetErrors } = useFormSubmit(
        form,
        action,
        {
            preserveScroll: true,
            fallback: 'That item could not be added.',
            transform: (values) => ({
                name: values.name,
                value: values.value,
                description: values.description || null,
                shared_group_id: groupId(values.shared_group_id),
            }),
            onSuccess: () => {
                toast.success('Added to the shared vault');
                close();
            },
        },
    );

    return (
        <DialogShell
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            title="Add to the shared vault"
            description="Encrypted at rest. Reading it back always costs a PIN."
        >
            <form onSubmit={submit} className={formClass}>
                <Field
                    id="shared-name"
                    label="Name"
                    required
                    autoFocus
                    placeholder="Staging root password"
                    wrapperClassName="mb-0"
                    error={errorFor('name')}
                    {...form.register('name')}
                />
                <TextareaField
                    id="shared-value"
                    label="Value"
                    required
                    rows={3}
                    spellCheck={false}
                    className="font-mono"
                    wrapperClassName="mb-0"
                    error={errorFor('value')}
                    {...form.register('value')}
                />
                <Field
                    id="shared-note"
                    label="Note"
                    aside={
                        <span className="text-[11px] text-fg-3">optional</span>
                    }
                    placeholder="Where it comes from, what it opens"
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
                    label="Add"
                    busyLabel="Adding…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}

/** Renames, re-files, or replaces the value of one shared item. */
export function EditSharedItemDialog({
    item,
    groups,
    action,
    onClose,
}: {
    item: SharedItem;
    groups: SharedGroup[];
    action: string;
    onClose: () => void;
}) {
    const form = useForm<SharedItemValues>({
        resolver: zodResolver(sharedItemSchema),
        mode: 'onChange',
        defaultValues: {
            name: item.name,
            value: '',
            description: item.description ?? '',
            shared_group_id:
                item.groupId === null ? '' : String(item.groupId),
        },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        action,
        {
            method: 'patch',
            preserveScroll: true,
            fallback: 'That could not be updated.',
            transform: (values) => ({
                name: values.name,
                // Left empty means "leave the stored value alone", which is
                // also the only thing an edit can do to a file.
                value: values.value ? values.value : null,
                description: values.description || null,
                shared_group_id: groupId(values.shared_group_id),
            }),
            onSuccess: () => {
                toast.success('Updated');
                onClose();
            },
        },
    );

    return (
        <DialogShell
            open
            onOpenChange={(next) => !next && onClose()}
            title={`Edit ${item.name}`}
            description={
                item.type === 'file'
                    ? 'A file keeps its contents - only its label and group change here.'
                    : 'Leave the value blank to keep the one already stored.'
            }
        >
            <form onSubmit={submit} className={formClass}>
                <Field
                    id="shared-edit-name"
                    label="Name"
                    required
                    autoFocus
                    wrapperClassName="mb-0"
                    error={errorFor('name')}
                    {...form.register('name')}
                />
                {item.type === 'secret' && (
                    <TextareaField
                        id="shared-edit-value"
                        label="New value"
                        aside={
                            <span className="text-[11px] text-fg-3">
                                blank keeps the current one
                            </span>
                        }
                        rows={3}
                        spellCheck={false}
                        className="font-mono"
                        wrapperClassName="mb-0"
                        error={errorFor('value')}
                        {...form.register('value')}
                    />
                )}
                <Field
                    id="shared-edit-note"
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
                    onCancel={onClose}
                    busy={busy}
                    disabled={!form.formState.isValid}
                    label="Save"
                    busyLabel="Saving…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}
