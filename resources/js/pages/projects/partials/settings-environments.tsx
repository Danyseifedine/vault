import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DestructiveDialog, InlineRename, Select } from '@/components/vault';
import { flash as go } from '@/lib/visit';
import type { ProjectAdmin } from '../types';
import { AddRow, rowDeleteClass, SettingsCard } from './settings-parts';

const REQUIREMENTS = [
    { value: 'none', label: 'No PIN' },
    { value: 'pin', label: 'PIN' },
    { value: 'pin_password', label: 'PIN + password' },
];

const SENSITIVITIES = ['public', 'sensitive', 'critical'];

/** The environments card: rename, delete, and the reveal-policy matrix. */
export function EnvironmentsCard({
    admin,
    basePath,
    canEditPolicies,
}: {
    admin: ProjectAdmin;
    basePath: string;
    canEditPolicies: boolean;
}) {
    // Deleting an environment destroys its values and history for real, which
    // deserves a dialog that says so, not a browser confirm.
    const [removing, setRemoving] = useState<
        ProjectAdmin['environments'][number] | null
    >(null);

    return (
        <SettingsCard
            title="Environments"
            hint="and what a reveal costs in each"
        >
            {admin.environments.map((environment) => (
                <div
                    key={environment.id}
                    className="border-b border-line px-[15px] py-2.5"
                >
                    <div className="mb-2 flex items-center gap-2">
                        <span className="font-mono text-[12.5px]">
                            {environment.slug}
                        </span>
                        <InlineRename
                            value={environment.name}
                            className="text-[11px] text-fg-3"
                            onRename={(next) =>
                                router.patch(
                                    `${basePath}/environments/${environment.slug}`,
                                    { name: next },
                                    go('Environment renamed'),
                                )
                            }
                        />
                        {admin.environments.length > 1 && (
                            <button
                                type="button"
                                onClick={() => setRemoving(environment)}
                                className={rowDeleteClass}
                            >
                                Delete
                            </button>
                        )}
                    </div>
                    <div className="flex flex-col gap-1.5">
                        {SENSITIVITIES.map((sensitivity) => (
                            <div
                                key={sensitivity}
                                className="flex items-center gap-2 text-[11.5px]"
                            >
                                <span className="w-[68px] text-fg-3">
                                    {sensitivity}
                                </span>
                                <Select
                                    size="sm"
                                    label={`${sensitivity} in ${environment.slug}`}
                                    className="w-[152px]"
                                    value={environment.policies[sensitivity]}
                                    disabled={!canEditPolicies}
                                    onChange={(requirement) =>
                                        router.put(
                                            `${basePath}/environments/${environment.slug}/reveal-policy`,
                                            { sensitivity, requirement },
                                            go(
                                                `${sensitivity} in ${environment.slug} updated`,
                                            ),
                                        )
                                    }
                                    options={REQUIREMENTS}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            ))}
            <AddRow
                placeholder="staging"
                onAdd={(value) =>
                    router.post(
                        `${basePath}/environments`,
                        { name: value },
                        go('Environment created'),
                    )
                }
            />

            {removing && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setRemoving(null)}
                    title={`Delete ${removing.name}?`}
                    description="Unlike deleting the project, this is not soft."
                    confirmWord={removing.slug}
                    consequences={[
                        'Every value stored in this environment is destroyed.',
                        'Its version history goes with it - there is nothing to roll back to.',
                        'Members granted access only here lose their way in.',
                    ]}
                    action={`${basePath}/environments/${removing.slug}`}
                    buttonLabel="Delete environment"
                    successMessage="Environment deleted"
                    onDeleted={() => setRemoving(null)}
                />
            )}
        </SettingsCard>
    );
}
