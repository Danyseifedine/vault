import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    SensitivityPicker,
    DialogActions,
    Field,
    fieldClass,
} from '@/components/vault';
import { classifyGroups, classifySensitivity } from '@/lib/env-classify';
import { cn } from '@/lib/utils';
import { variableSchema, variableValueSchema } from '@/lib/validation/vault';
import type {
    VariableValues,
    VariableValueValues,
} from '@/lib/validation/vault';
import type { ProjectAdmin, ProjectVariable } from '../types';
import { GroupPicker } from './group-picker';

/** Defines a new variable for the whole project - its value comes after. */
export function NewVariableDialog({
    open,
    onOpenChange,
    admin,
    action,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    admin: ProjectAdmin;
    action: string;
}) {
    const [busy, setBusy] = useState(false);
    // Only set while a brand new group is being typed - see GroupPicker.
    const [groupName, setGroupName] = useState<string | null>(null);

    const form = useForm<VariableValues>({
        resolver: zodResolver(variableSchema),
        mode: 'onChange',
        defaultValues: {
            key: '',
            description: '',
            sensitivity: 'sensitive',
            change_safety: 'safe',
            group_id: null,
        },
    });

    const key = form.watch('key');
    const sensitivity = form.watch('sensitivity');
    const breaking = form.watch('change_safety') === 'breaking';
    const keyError = form.formState.errors.key?.message;

    // Suggest a sensitivity from the key as it is typed, until the person picks
    // one themselves - then their choice stands and typing stops overriding it.
    // The value is set separately (per environment), so this is key-only; the
    // same EnvClassifier that runs on import decides.
    const [autoSuggest, setAutoSuggest] = useState(true);
    useEffect(() => {
        if (autoSuggest && key.trim() !== '') {
            form.setValue('sensitivity', classifySensitivity(key));
        }
    }, [key, autoSuggest, form]);

    // Pre-file into an EXISTING group whose name the key maps to (Database,
    // Payments…). Never invents one - a group nobody typed should not appear -
    // and steps aside the moment the person picks a group themselves.
    const [autoGroup, setAutoGroup] = useState(true);
    useEffect(() => {
        if (!autoGroup || key.trim() === '') {
            return;
        }

        const guess = classifyGroups([key])[key];
        const match =
            guess === null || guess === undefined
                ? undefined
                : admin.groups.find(
                      (g) => g.name.toLowerCase() === guess.toLowerCase(),
                  );

        form.setValue('group_id', match?.id ?? null);
    }, [key, autoGroup, admin.groups, form]);

    const close = () => {
        onOpenChange(false);
        form.reset();
        setGroupName(null);
        setAutoSuggest(true);
        setAutoGroup(true);
    };

    const submit = form.handleSubmit((values) => {
        setBusy(true);

        router.post(
            action,
            { ...values, group_name: groupName },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`${values.key} created`);
                    close();
                },
                onError: (errors) =>
                    errors.key
                        ? form.setError('key', { message: errors.key })
                        : toast.error('That variable could not be created.'),
                onFinish: () => setBusy(false),
            },
        );
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
        >
            <DialogContent className="w-[420px] border-line-2 bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        New variable
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        Defined once for the project. Each environment gets its
                        own value.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="flex flex-col gap-[15px] p-[17px] pt-1"
                >
                    <Field
                        id="variable-key"
                        label="Key"
                        required
                        autoFocus
                        spellCheck={false}
                        placeholder="DATABASE_URL"
                        className="font-mono"
                        wrapperClassName="mb-0"
                        error={keyError}
                        {...form.register('key')}
                    />

                    <GroupPicker
                        groups={admin.groups}
                        id={form.watch('group_id')}
                        name={groupName}
                        onId={(id) => {
                            setAutoGroup(false);
                            form.setValue('group_id', id);
                        }}
                        onName={(name) => {
                            setAutoGroup(false);
                            setGroupName(name);
                        }}
                        canCreate={admin.can.manageGroups}
                    />

                    <div>
                        <div className="mb-1.5 flex items-center gap-1.5 text-xs text-fg-2">
                            Sensitivity
                            {autoSuggest && key.trim() !== '' && (
                                <span className="text-[10.5px] text-fg-3">
                                    · guessed from the key
                                </span>
                            )}
                        </div>
                        <SensitivityPicker
                            value={sensitivity}
                            onChange={(level) => {
                                // A deliberate pick wins from here on.
                                setAutoSuggest(false);
                                form.setValue('sensitivity', level);
                            }}
                        />
                    </div>

                    <label className="flex cursor-pointer items-center gap-2 text-[12.5px] text-fg-2">
                        <input
                            type="checkbox"
                            checked={breaking}
                            onChange={(e) =>
                                form.setValue(
                                    'change_safety',
                                    e.target.checked ? 'breaking' : 'safe',
                                )
                            }
                        />
                        Changing this breaks things - warn before edits
                    </label>

                    <DialogActions
                        onCancel={close}
                        busy={busy}
                        disabled={!form.formState.isValid}
                        label="Create"
                        busyLabel="Creating…"
                        className="mt-0"
                    />
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Sets one variable's value in ONE environment. */
export function SetValueDialog({
    variable,
    env,
    action,
    onClose,
}: {
    variable: ProjectVariable;
    env: string;
    action: string;
    onClose: () => void;
}) {
    const [busy, setBusy] = useState(false);

    const form = useForm<VariableValueValues>({
        resolver: zodResolver(variableValueSchema),
        mode: 'onChange',
        defaultValues: { value: '' },
    });

    const submit = form.handleSubmit((values) => {
        setBusy(true);

        router.put(action, values, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`${variable.key} updated in ${env}`);
                onClose();
            },
            onError: (errors) =>
                toast.error(errors.value ?? 'That value was refused.'),
            onFinish: () => setBusy(false),
        });
    });

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="w-[420px] border-line-2 bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        Set {variable.key} in {env}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        {variable.unsafe
                            ? 'Marked as breaking - changing this is expected to require a redeploy.'
                            : 'The previous value is kept in history and can be rolled back.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="flex flex-col gap-[15px] p-[17px] pt-1"
                >
                    <div>
                        <label
                            htmlFor="variable-value"
                            className="mb-1.5 block text-xs text-fg-2"
                        >
                            Value
                        </label>
                        <textarea
                            id="variable-value"
                            autoFocus
                            spellCheck={false}
                            rows={3}
                            className={cn(
                                fieldClass,
                                'h-auto resize-y py-2.5 font-mono',
                            )}
                            {...form.register('value')}
                        />
                        <div className="mt-1.5 text-[11.5px] text-fg-3">
                            Encrypted before it reaches the database.
                        </div>
                    </div>
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
