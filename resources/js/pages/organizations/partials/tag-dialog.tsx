import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    DialogActions,
    DialogShell,
    Field,
    FormAlert,
    formClass,
    SelectField,
} from '@/components/vault';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { tagCreateSchema } from '@/lib/validation/vault';
import type { TagCreateValues } from '@/lib/validation/vault';
import type { OrgScope, OrganizationSummary } from '../types';

/**
 * Creating a tag, in the same dialog flow as every other create in the
 * product. The scope is chosen here and never again - widening one later
 * would retroactively legitimise attachments that were refused at the time.
 */
export function TagCreateDialog({
    open,
    onOpenChange,
    organization,
    scopes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    organization: OrganizationSummary;
    scopes: OrgScope[];
}) {
    const form = useForm<TagCreateValues>({
        resolver: zodResolver(tagCreateSchema),
        mode: 'onChange',
        defaultValues: {
            name: '',
            // Owners start on org-wide; everyone else must pick a scope they
            // are actually allowed to fill.
            target: organization.canCreateGlobalTags ? 'global' : '',
        },
    });

    const close = () => {
        onOpenChange(false);
        form.reset();
        resetErrors();
    };

    const { busy, serverError, errorFor, submit, resetErrors } = useFormSubmit(
        form,
        `/orgs/${organization.slug}/tags`,
        {
            preserveScroll: true,
            fallback: 'That tag could not be created.',
            transform: (values) => ({
                name: values.name.trim(),
                project_id: values.target.startsWith('project:')
                    ? Number(values.target.slice(8))
                    : null,
                environment_id: values.target.startsWith('env:')
                    ? Number(values.target.slice(4))
                    : null,
            }),
            onSuccess: () => {
                toast.success('Tag created');
                close();
            },
        },
    );

    return (
        <DialogShell
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            title="New tag"
            description="A label with a reach. Scope is fixed once created - that is what keeps “prod-only” meaningful."
        >
            <form onSubmit={submit} className={formClass}>
                <Field
                    id="tag-name"
                    label="Name"
                    required
                    autoFocus
                    placeholder="rotate-soon"
                    maxLength={40}
                    wrapperClassName="mb-0"
                    error={errorFor('name')}
                    {...form.register('name')}
                />
                <Controller
                    control={form.control}
                    name="target"
                    render={({ field }) => (
                        <SelectField
                            label="Scope"
                            required={!organization.canCreateGlobalTags}
                            size="lg"
                            wrapperClassName="mb-0"
                            value={field.value}
                            onChange={field.onChange}
                            placeholder="Pick a scope"
                            searchPlaceholder="Filter scopes…"
                            emptyLabel="No scope matches."
                            error={errorFor('target')}
                            options={[
                                {
                                    value: 'global',
                                    label: 'Organization-wide',
                                    meta: organization.canCreateGlobalTags
                                        ? undefined
                                        : 'owner only',
                                    disabled: !organization.canCreateGlobalTags,
                                },
                                // A project you cannot label is offered but
                                // disabled - hiding it would make the list
                                // look like the project does not exist.
                                ...scopes.flatMap((project) => [
                                    {
                                        value: `project:${project.id}`,
                                        label: 'whole project',
                                        group: project.name,
                                        meta: project.canCreateTags
                                            ? undefined
                                            : 'no tags.create',
                                        disabled: !project.canCreateTags,
                                    },
                                    ...project.environments.map(
                                        (environment) => ({
                                            value: `env:${environment.id}`,
                                            label: environment.slug,
                                            group: project.name,
                                            disabled: !project.canCreateTags,
                                        }),
                                    ),
                                ]),
                            ]}
                        />
                    )}
                />
                {serverError && <FormAlert>{serverError}</FormAlert>}
                <DialogActions
                    onCancel={close}
                    busy={busy}
                    disabled={!form.formState.isValid}
                    label="Create tag"
                    busyLabel="Creating…"
                    className="mt-0"
                />
            </form>
        </DialogShell>
    );
}
