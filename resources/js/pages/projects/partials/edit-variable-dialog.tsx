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
import { DialogActions, Field, SensitivityPicker } from '@/components/vault';
import { cn } from '@/lib/utils';
import { variableSchema } from '@/lib/validation/vault';
import type { VariableValues } from '@/lib/validation/vault';
import type { ProjectAdmin, ProjectVariable } from '../types';
import { GroupPicker } from './group-picker';

/**
 * Edits a variable's definition and its labels - key, sensitivity, apps, tags.
 * Its values are untouched; those belong to environments.
 */
export function EditVariableDialog({
    variable,
    admin,
    basePath,
    onClose,
}: {
    variable: ProjectVariable;
    admin: ProjectAdmin;
    basePath: string;
    onClose: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [groupName, setGroupName] = useState<string | null>(null);
    const [tags, setTags] = useState<number[]>(
        admin.tags
            .filter((t) => variable.tags?.includes(t.name))
            .map((t) => t.id),
    );

    const form = useForm<VariableValues>({
        resolver: zodResolver(variableSchema),
        mode: 'onChange',
        defaultValues: {
            key: variable.key,
            description: '',
            sensitivity: variable.sensitivity,
            change_safety: variable.unsafe ? 'breaking' : 'safe',
            // The payload carries the group's NAME (it is what the table
            // renders); the id comes from the project's own list.
            group_id:
                admin.groups.find((group) => group.name === variable.group)
                    ?.id ?? null,
        },
    });

    const sensitivity = form.watch('sensitivity');
    const keyError = form.formState.errors.key?.message;

    const submit = form.handleSubmit((values) => {
        setBusy(true);

        // Description is not edited here, so it is left out of the payload
        // entirely - sending the form's empty default would wipe whatever is
        // stored.
        const definition = {
            key: values.key,
            sensitivity: values.sensitivity,
            change_safety: values.change_safety,
            group_id: values.group_id,
        };

        // Two endpoints because they are two different authorities: the
        // definition, and the labels. The tags PUT is chained INSIDE the
        // definition's success and is the thing that finally reports done -
        // otherwise a failed tag sync would be hidden behind an "updated" toast
        // that already fired.
        router.patch(
            `${basePath}/variables/${variable.id}`,
            { ...definition, group_name: groupName },
            {
                preserveScroll: true,
                onSuccess: () =>
                    router.put(
                        `${basePath}/variables/${variable.id}/tags`,
                        { ids: tags },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                toast.success(`${values.key} updated`);
                                onClose();
                            },
                            onError: () =>
                                toast.error(
                                    `${values.key} was saved, but its tags could not be updated.`,
                                ),
                            onFinish: () => setBusy(false),
                        },
                    ),
                onError: (errors) => {
                    if (errors.key) {
                        form.setError('key', { message: errors.key });
                    } else {
                        toast.error('That variable could not be updated.');
                    }

                    setBusy(false);
                },
            },
        );
    });

    const chip = (on: boolean) =>
        cn(
            'h-7 cursor-pointer rounded-lg border px-[11px] text-xs transition-colors',
            on
                ? 'border-primary bg-primary-soft text-primary'
                : 'border-line-2 bg-transparent text-fg-2 hover:bg-panel-2',
        );

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="w-[420px] border-line-2 bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        Edit {variable.key}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        The definition and its labels. Values live per
                        environment.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="flex max-h-[70vh] flex-col gap-[15px] overflow-auto p-[17px] pt-1"
                >
                    <Field
                        id="edit-key"
                        label="Key"
                        required
                        spellCheck={false}
                        className="font-mono"
                        wrapperClassName="mb-0"
                        error={keyError}
                        {...form.register('key')}
                    />

                    <GroupPicker
                        groups={admin.groups}
                        id={form.watch('group_id')}
                        name={groupName}
                        onId={(id) => form.setValue('group_id', id)}
                        onName={setGroupName}
                        canCreate={admin.can.manageGroups}
                    />

                    <div>
                        <div className="mb-1.5 block text-xs text-fg-2">
                            Sensitivity
                        </div>
                        <SensitivityPicker
                            value={sensitivity}
                            onChange={(level) =>
                                form.setValue('sensitivity', level)
                            }
                        />
                    </div>

                    {admin.tags.length > 0 && (
                        <div>
                            <div className="mb-1.5 block text-xs text-fg-2">
                                Tags
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {admin.tags.map((tag) => (
                                    <button
                                        key={tag.id}
                                        type="button"
                                        title={`${tag.scope} tag`}
                                        onClick={() =>
                                            setTags((c) =>
                                                c.includes(tag.id)
                                                    ? c.filter(
                                                          (id) => id !== tag.id,
                                                      )
                                                    : [...c, tag.id],
                                            )
                                        }
                                        className={chip(tags.includes(tag.id))}
                                    >
                                        {tag.name}
                                    </button>
                                ))}
                            </div>
                            <div className="mt-[7px] text-[11.5px] text-fg-3">
                                An environment tag only sticks if this variable
                                has a value there.
                            </div>
                        </div>
                    )}

                    <DialogActions
                        onCancel={onClose}
                        busy={busy}
                        disabled={!form.formState.isValid}
                        label="Save"
                        busyLabel="Saving…"
                        className="mt-0"
                    />
                </form>
            </DialogContent>
        </Dialog>
    );
}
