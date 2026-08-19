import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { DestructiveDialog, FieldError, Locked } from '@/components/vault';
import { cn } from '@/lib/utils';
import { projectSchema, projectSecuritySchema } from '@/lib/validation/vault';
import type { ProjectSecurityValues } from '@/lib/validation/vault';
import { flash as go } from '@/lib/visit';
import type { ProjectAdmin, ProjectSummary } from '../types';
import { EnvironmentsCard } from './settings-environments';
import { SettingsCard, settingsField as field } from './settings-parts';

export function SettingsScreen({
    project,
    admin,
    basePath,
}: {
    project: ProjectSummary;
    admin: ProjectAdmin;
    basePath: string;
}) {
    const { can } = admin;
    const [name, setName] = useState(project.name);
    const [renaming, setRenaming] = useState(false);
    const [deleting, setDeleting] = useState(false);

    // The name is the one free-text project setting, so it gets the same 1-120
    // rule the server holds - checked here so Rename is dead until the name
    // would actually save, and an over-long paste is refused in place.
    const nameCheck = projectSchema.shape.name.safeParse(name);
    const nameError = name !== project.name && !nameCheck.success
        ? nameCheck.error.issues[0]?.message
        : undefined;
    const canRename = nameCheck.success && name !== project.name && !renaming;

    // The lockout policy is numbers with hard bounds - exactly what a schema
    // is for. FormData-and-Number() let a cleared field post attempts: 0.
    const security = useForm<ProjectSecurityValues>({
        resolver: zodResolver(projectSecuritySchema),
        mode: 'onChange',
        defaultValues: {
            audit_views: admin.settings.auditViews,
            pin_max_attempts: admin.settings.pinMaxAttempts,
            pin_lockout_minutes: admin.settings.pinLockoutMinutes,
        },
    });

    const submitSecurity = security.handleSubmit((values) =>
        router.put(`${basePath}/settings`, values, go('Settings updated')),
    );

    return (
        <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                Settings
            </h1>
            <p className="mb-[18px] text-[13px] text-fg-2">
                The shape of this project and what a reveal costs inside it.
                Every change here is audited.
            </p>

            {/* Every card is on screen for every viewer - locked, not hidden,
                when the viewer's grants do not cover it. The server is what
                actually refuses; this is just honesty about what exists. */}
            <div className="grid grid-cols-2 items-start gap-3.5 max-lg:grid-cols-1">
                <Locked
                    when={!can.updateSettings}
                    reason="Changing project settings takes settings.update"
                >
                    <SettingsCard title="Project">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();

                                if (!canRename) {
                                    return;
                                }

                                router.patch(
                                    basePath,
                                    {
                                        name: name.trim(),
                                        description:
                                            project.description ?? null,
                                    },
                                    {
                                        ...go('Project updated'),
                                        onStart: () => setRenaming(true),
                                        onFinish: () => setRenaming(false),
                                    },
                                );
                            }}
                            className="flex flex-wrap items-center gap-2 border-b border-line px-[15px] py-2.5"
                        >
                            <input
                                value={name}
                                maxLength={120}
                                onChange={(e) => setName(e.target.value)}
                                className={cn(
                                    field,
                                    'flex-1',
                                    nameError && 'border-crit',
                                )}
                            />
                            <button
                                type="submit"
                                disabled={!canRename}
                                className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] transition-colors hover:bg-panel-2 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {renaming ? 'Renaming…' : 'Rename'}
                            </button>
                            {nameError && (
                                <div className="w-full">
                                    <FieldError message={nameError} />
                                </div>
                            )}
                        </form>
                        <div className="flex items-center gap-2 px-[15px] py-2.5">
                            <div className="text-[11.5px] text-fg-3">
                                Deleting is reversible - values and history are
                                kept.
                            </div>
                            <button
                                type="button"
                                onClick={() => setDeleting(true)}
                                className="ml-auto h-[30px] cursor-pointer rounded-lg border border-crit-soft bg-crit-soft px-3 text-[12.5px] text-crit transition-colors hover:bg-crit hover:text-crit-foreground"
                            >
                                Delete project
                            </button>
                        </div>
                    </SettingsCard>
                </Locked>

                <Locked
                    when={!can.updateSettings}
                    reason="Changing project settings takes settings.update"
                >
                    <SettingsCard
                        title="Security"
                        hint="reveal attempts and lockout"
                    >
                        <form
                            onSubmit={submitSecurity}
                            className="flex flex-col gap-2.5 px-[15px] py-3"
                        >
                            <label className="flex items-center gap-2 text-[12.5px] text-fg-2">
                                <input
                                    type="checkbox"
                                    {...security.register('audit_views')}
                                />
                                Record reveals in the audit log
                            </label>
                            <div className="text-[11px] text-fg-3">
                                Changes are always recorded - no setting can
                                turn that off.
                            </div>
                            <label className="flex items-center gap-2 text-[12.5px] text-fg-2">
                                Attempts before lockout
                                <input
                                    type="number"
                                    min={1}
                                    max={20}
                                    {...security.register('pin_max_attempts', {
                                        valueAsNumber: true,
                                    })}
                                    className={cn(
                                        field,
                                        'ml-auto w-[72px]',
                                        security.formState.errors
                                            .pin_max_attempts && 'border-crit',
                                    )}
                                />
                            </label>
                            <label className="flex items-center gap-2 text-[12.5px] text-fg-2">
                                Lockout minutes
                                <input
                                    type="number"
                                    min={1}
                                    max={1440}
                                    {...security.register(
                                        'pin_lockout_minutes',
                                        { valueAsNumber: true },
                                    )}
                                    className={cn(
                                        field,
                                        'ml-auto w-[72px]',
                                        security.formState.errors
                                            .pin_lockout_minutes &&
                                            'border-crit',
                                    )}
                                />
                            </label>
                            <FieldError
                                message={
                                    security.formState.errors.pin_max_attempts
                                        ?.message ??
                                    security.formState.errors
                                        .pin_lockout_minutes?.message
                                }
                            />
                            <button
                                type="submit"
                                disabled={!security.formState.isValid}
                                className="mt-1 h-[30px] cursor-pointer rounded-lg border-0 bg-primary px-3 text-[12.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Save
                            </button>
                        </form>
                    </SettingsCard>
                </Locked>

                <Locked
                    when={!can.manageEnvironments}
                    reason="Adding or removing environments takes environments.manage"
                >
                    <EnvironmentsCard
                        admin={admin}
                        basePath={basePath}
                        canEditPolicies={can.updateSettings}
                    />
                </Locked>
            </div>

            <DestructiveDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={`Delete ${project.name}?`}
                description="This one is reversible - the project is soft-deleted."
                consequences={[
                    'The project disappears for every member.',
                    'Variables, values and history are kept and can be restored.',
                ]}
                action={basePath}
                buttonLabel="Delete project"
                successMessage="Project deleted"
            />
        </div>
    );
}
