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
import {
    personalItemSchema,
    personalSecretSchema,
} from '@/lib/validation/personal';
import type {
    PersonalItemValues,
    PersonalSecretValues,
} from '@/lib/validation/personal';
import type { PersonalGroup, PersonalItem } from '../types';

/**
 * The personal vault's field dialogs: storing a new secret, and editing either
 * kind of item. Uploading a file is its own file, because bytes bring a
 * dropzone and a preview with them.
 *
 * Both validate with react-hook-form + zod and put the message under the field
 * that earned it.
 */

/** Stores a new private credential. Encrypted before it reaches the database. */
export function NewSecretDialog({
    open,
    onOpenChange,
    groups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: PersonalGroup[];
}) {
    const form = useForm<PersonalSecretValues>({
        resolver: zodResolver(personalSecretSchema),
        mode: 'onChange',
        defaultValues: { name: '', value: '', personal_group_id: '' },
    });

    const close = () => {
        onOpenChange(false);
        form.reset();
        resetErrors();
    };

    const { busy, serverError, errorFor, submit, resetErrors } = useFormSubmit(
        form,
        '/personal/secrets',
        {
            preserveScroll: true,
            fallback: 'That could not be saved.',
            transform: (values) => ({
                name: values.name,
                value: values.value,
                personal_group_id: groupId(values.personal_group_id),
            }),
            onSuccess: () => {
                toast.success('Saved to your vault');
                close();
            },
        },
    );

    return (
        <DialogShell
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            title="New personal secret"
            description="Only you can read this - there is no administrative override."
        >
            <form onSubmit={submit} className={formClass}>
                <Field
                    id="secret-name"
                    label="Name"
                    required
                    autoFocus
                    placeholder="GitHub token"
                    wrapperClassName="mb-0"
                    error={errorFor('name')}
                    {...form.register('name')}
                />
                <TextareaField
                    id="secret-value"
                    label="Value"
                    required
                    rows={3}
                    spellCheck={false}
                    className="font-mono"
                    wrapperClassName="mb-0"
                    error={errorFor('value')}
                    {...form.register('value')}
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
                    label="Save"
                    busyLabel="Saving…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}

/**
 * Renames, refiles, and - for a secret - rotates the value.
 *
 * Leaving the value blank keeps the stored one, so an edit made to fix a typo
 * in the name can never blank a token. A file keeps its contents: replacing
 * those is an upload, not an edit.
 */
export function EditItemDialog({
    item,
    groups,
    onClose,
}: {
    item: PersonalItem;
    groups: PersonalGroup[];
    onClose: () => void;
}) {
    const form = useForm<PersonalItemValues>({
        resolver: zodResolver(personalItemSchema),
        mode: 'onChange',
        defaultValues: {
            name: item.name,
            value: '',
            description: item.description ?? '',
            personal_group_id:
                item.groupId === null ? '' : String(item.groupId),
        },
    });

    const { busy, serverError, errorFor, submit } = useFormSubmit(
        form,
        `/personal/items/${item.id}`,
        {
            method: 'patch',
            preserveScroll: true,
            fallback: 'That could not be updated.',
            transform: (values) => ({
                name: values.name,
                // Blank means "leave the stored value alone", which is also
                // the only thing an edit can do to a file.
                value: values.value ? values.value : null,
                description: values.description || null,
                personal_group_id: groupId(values.personal_group_id),
            }),
            onSuccess: () => {
                toast.success(
                    form.getValues('value') ? 'Value replaced' : 'Updated',
                );
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
                    ? 'A file keeps its contents - only its label and folder change here.'
                    : 'Leave the value blank to keep the one already stored.'
            }
        >
            <form onSubmit={submit} className={formClass}>
                <Field
                    id="item-name"
                    label="Name"
                    required
                    autoFocus
                    wrapperClassName="mb-0"
                    error={errorFor('name')}
                    {...form.register('name')}
                />
                {item.type === 'secret' && (
                    <TextareaField
                        id="item-value"
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
                    id="item-note"
                    label="Note"
                    aside={
                        <span className="text-[11px] text-fg-3">optional</span>
                    }
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
