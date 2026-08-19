import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    coveredByWider,
    ENV_ACTIONS,
    hasGrant,
    ORG_PERMISSIONS,
    PROJECT_PERMISSIONS,
    setScope,
    toggleGrant,
} from '@/lib/access';
import type { GrantRow } from '@/lib/access';
import { cn } from '@/lib/utils';

type ChecklistEnvironment = { id: number; slug: string; name: string };
type ChecklistProject = {
    id: number;
    slug: string;
    name: string;
    environments: ChecklistEnvironment[];
};

/**
 * The whole access model as one tree of plain checkboxes - no roles, no
 * presets, no vocabulary beyond the permissions themselves. A box ticked at a
 * wider scope shows the narrower ones locked-and-checked: the wider row
 * already covers them, including things created later.
 */
export function GrantChecklist({
    scopes,
    value,
    onChange,
    editableProjectId,
}: {
    scopes: ChecklistProject[];
    value: GrantRow[];
    onChange: (rows: GrantRow[]) => void;
    /** Project-screen mode: everything outside this project renders locked. */
    editableProjectId?: number;
}) {
    const orgLocked = editableProjectId !== undefined;
    const shown = orgLocked
        ? scopes.filter((project) => project.id === editableProjectId)
        : scopes;

    return (
        <div className="flex flex-col gap-2.5">
            <ScopeGroup
                title="Entire organization"
                hint="covers every project, including future ones"
                locked={orgLocked}
                onAll={() =>
                    onChange(
                        setScope(
                            value,
                            [
                                ...ORG_PERMISSIONS,
                                ...PROJECT_PERMISSIONS,
                                ...ENV_ACTIONS,
                            ].map((p) => p.value),
                        ),
                    )
                }
                onNone={() => onChange(setScope(value, []))}
            >
                <PermissionColumns>
                    {[
                        ...ORG_PERMISSIONS,
                        ...PROJECT_PERMISSIONS,
                        ...ENV_ACTIONS,
                    ].map((permission) => (
                        <CheckRow
                            key={permission.value}
                            label={permission.label}
                            checked={hasGrant(value, permission.value)}
                            locked={orgLocked}
                            onToggle={() =>
                                onChange(toggleGrant(value, permission.value))
                            }
                        />
                    ))}
                </PermissionColumns>
            </ScopeGroup>

            {shown.map((project) => (
                <ScopeGroup
                    key={project.id}
                    title={project.name}
                    hint="this project only"
                    onAll={() =>
                        onChange(
                            setScope(
                                value,
                                [...PROJECT_PERMISSIONS, ...ENV_ACTIONS].map(
                                    (p) => p.value,
                                ),
                                project.id,
                            ),
                        )
                    }
                    onNone={() => {
                        let next = setScope(value, [], project.id);

                        for (const environment of project.environments) {
                            next = setScope(
                                next,
                                [],
                                project.id,
                                environment.id,
                            );
                        }

                        onChange(next);
                    }}
                >
                    <PermissionColumns>
                        {[...PROJECT_PERMISSIONS, ...ENV_ACTIONS].map(
                            (permission) => (
                                <CheckRow
                                    key={permission.value}
                                    label={permission.label}
                                    checked={
                                        hasGrant(
                                            value,
                                            permission.value,
                                            project.id,
                                        ) ||
                                        coveredByWider(
                                            value,
                                            permission.value,
                                            project.id,
                                        )
                                    }
                                    locked={coveredByWider(
                                        value,
                                        permission.value,
                                        project.id,
                                    )}
                                    onToggle={() =>
                                        onChange(
                                            toggleGrant(
                                                value,
                                                permission.value,
                                                project.id,
                                            ),
                                        )
                                    }
                                />
                            ),
                        )}
                    </PermissionColumns>

                    {project.environments.map((environment) => (
                        <div
                            key={environment.id}
                            className="mt-1 border-t border-line pt-2"
                        >
                            <div className="mb-1.5 font-mono text-[11px] text-fg-3">
                                {environment.slug} only
                            </div>
                            <PermissionColumns>
                                {ENV_ACTIONS.map((permission) => (
                                    <CheckRow
                                        key={permission.value}
                                        label={permission.label}
                                        checked={
                                            hasGrant(
                                                value,
                                                permission.value,
                                                project.id,
                                                environment.id,
                                            ) ||
                                            coveredByWider(
                                                value,
                                                permission.value,
                                                project.id,
                                                environment.id,
                                            )
                                        }
                                        locked={coveredByWider(
                                            value,
                                            permission.value,
                                            project.id,
                                            environment.id,
                                        )}
                                        onToggle={() =>
                                            onChange(
                                                toggleGrant(
                                                    value,
                                                    permission.value,
                                                    project.id,
                                                    environment.id,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </PermissionColumns>
                        </div>
                    ))}
                </ScopeGroup>
            ))}
        </div>
    );
}

function ScopeGroup({
    title,
    hint,
    locked = false,
    onAll,
    onNone,
    children,
}: {
    title: string;
    hint: string;
    locked?: boolean;
    onAll: () => void;
    onNone: () => void;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(!locked);

    return (
        <div className="rounded-[9px] border border-line">
            <div className="flex items-center gap-2 px-2.5 py-2">
                <button
                    type="button"
                    onClick={() => setOpen((current) => !current)}
                    className="flex cursor-pointer items-center gap-1.5 border-0 bg-transparent p-0 text-left text-[12.5px] font-semibold"
                >
                    {open ? (
                        <ChevronDown
                            className="size-3 text-fg-3"
                            strokeWidth={2}
                        />
                    ) : (
                        <ChevronRight
                            className="size-3 text-fg-3"
                            strokeWidth={2}
                        />
                    )}
                    {title}
                </button>
                <span className="text-[10.5px] text-fg-3">{hint}</span>
                {!locked && (
                    <span className="ml-auto flex gap-1">
                        <GroupButton onClick={onAll}>all</GroupButton>
                        <GroupButton onClick={onNone}>Clear</GroupButton>
                    </span>
                )}
            </div>
            {open && (
                <div
                    className={cn(
                        'border-t border-line px-2.5 py-2',
                        locked && 'pointer-events-none opacity-55',
                    )}
                >
                    {children}
                </div>
            )}
        </div>
    );
}

function GroupButton({
    onClick,
    children,
}: {
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="h-[22px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
        >
            {children}
        </button>
    );
}

function PermissionColumns({ children }: { children: ReactNode }) {
    return (
        <div className="grid grid-cols-2 gap-x-3 gap-y-0.5 max-sm:grid-cols-1">
            {children}
        </div>
    );
}

function CheckRow({
    label,
    checked,
    locked,
    onToggle,
}: {
    label: string;
    checked: boolean;
    locked?: boolean;
    onToggle: () => void;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-center gap-2 py-0.5 text-xs text-fg-2',
                locked && 'cursor-not-allowed opacity-60',
            )}
            title={locked ? 'Covered by a wider grant' : undefined}
        >
            <input
                type="checkbox"
                className="accent-primary"
                checked={checked}
                disabled={locked}
                onChange={onToggle}
            />
            {label}
        </label>
    );
}
