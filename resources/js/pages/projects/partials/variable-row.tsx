import { RotateCcw, X } from 'lucide-react';
import { toast } from 'sonner';
import {
    LockedAction,
    SensitivityBadge,
    SensitivityDot,
    TagChip,
} from '@/components/vault';
import { cn } from '@/lib/utils';
import type { EnvironmentAbilities, ProjectVariable } from '../types';
import { requirementFor, revealLabel, valueFor } from './helpers';

/**
 * Shared by the table header and every row, so the columns line up.
 *
 * The actions column is sized for its widest possible content - reveal
 * ("PIN + pw", wherever the environment's policy demands both), rollback,
 * Edit, Set, delete and copy. Sizing
 * it to anything less does not wrap the buttons, it spills them left over the
 * sensitivity badge, because each row is its own grid.
 */
export const ROW_GRID =
    'grid grid-cols-[minmax(150px,1.5fr)_minmax(120px,1.35fr)_minmax(96px,150px)_96px_268px] gap-3';

// shrink-0 + nowrap: an action never squeezes or breaks its label in half.
const actionClass =
    'h-[25px] shrink-0 cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11.5px] whitespace-nowrap text-fg-2 transition-colors hover:bg-panel-3 hover:text-fg';

const iconClass =
    'h-[25px] w-[26px] shrink-0 cursor-pointer rounded-md border border-line-2 bg-transparent text-[11px] text-fg-2 transition-colors hover:bg-panel-3 hover:text-fg';

/**
 * One variable in one environment.
 *
 * A variable is defined for the whole project, so it appears in every
 * environment tab - the ones where nobody has set a value yet say so, which is
 * how a brand new key is found and filled in.
 */
export function VariableRow({
    variable,
    env,
    abilities,
    onReveal,
    onSetValue,
    onEdit,
    onHistory,
    onDelete,
}: {
    variable: ProjectVariable;
    env: string;
    abilities: EnvironmentAbilities;
    onReveal: (variable: ProjectVariable) => void;
    onSetValue: (variable: ProjectVariable) => void;
    onEdit: (variable: ProjectVariable) => void;
    onHistory: (variable: ProjectVariable) => void;
    onDelete: (variable: ProjectVariable) => void;
}) {
    const raw = valueFor(variable, env);
    const isSet = raw !== undefined;
    // A non-empty string is an inlined public value (the server sends it only
    // when revealing here is free and permitted). Everything else - secrets,
    // and public values held back because the policy or a missing grant would
    // gate them - arrives as '' and is masked, revealed through RevealGuard.
    const publicValue = raw !== undefined && raw !== '' ? raw : undefined;

    return (
        <div
            className={cn(
                ROW_GRID,
                'min-h-[42px] items-center border-b border-line px-[15px] transition-colors hover:bg-panel-2',
            )}
        >
            <div className="flex min-w-0 items-center gap-2">
                <SensitivityDot level={variable.sensitivity} />
                <span className="truncate font-mono text-xs">
                    {variable.key}
                </span>
                {variable.unsafe && (
                    <span
                        title="Changing this breaks things"
                        className="rounded border border-sens-soft bg-sens-soft px-1 py-px text-[9.5px] whitespace-nowrap text-sens"
                    >
                        breaks on change
                    </span>
                )}
            </div>
            {isSet ? (
                <div className="truncate font-mono text-xs text-fg-2">
                    {publicValue ?? '••••••••••••••••'}
                </div>
            ) : (
                <div className="text-[11.5px] text-fg-3">not set</div>
            )}
            <div className="flex flex-wrap gap-1">
                {variable.tags.slice(0, 2).map((tag) => (
                    <TagChip key={tag} label={tag} />
                ))}
                {variable.tags.length > 2 && (
                    <TagChip label={`+${variable.tags.length - 2}`} />
                )}
            </div>
            <SensitivityBadge level={variable.sensitivity} />
            <div className="flex justify-end gap-[5px]">
                {/* Nothing to reveal, roll back or copy until a value exists. */}
                {isSet &&
                    (abilities.reveal ? (
                        <button
                            type="button"
                            onClick={() => onReveal(variable)}
                            className={cn(
                                'h-[25px] shrink-0 cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11.5px] whitespace-nowrap transition-colors hover:bg-panel-3',
                                variable.sensitivity === 'critical'
                                    ? 'text-crit'
                                    : variable.sensitivity === 'sensitive'
                                      ? 'text-sens'
                                      : 'text-pub',
                            )}
                        >
                            {revealLabel(
                                requirementFor(variable, abilities),
                            )}
                        </button>
                    ) : (
                        <LockedAction
                            reason={`Revealing takes variables.reveal in ${env}`}
                        />
                    ))}
                {isSet &&
                    (abilities.rollback ? (
                        <button
                            type="button"
                            title="Change history and rollback"
                            onClick={() => onHistory(variable)}
                            className={iconClass}
                        >
                            <RotateCcw
                                className="mx-auto size-3"
                                strokeWidth={1.75}
                            />
                        </button>
                    ) : (
                        <LockedAction
                            reason={`Rolling back takes variables.rollback in ${env}`}
                        />
                    ))}
                {variable.canEdit ? (
                    <button
                        type="button"
                        title="Edit key, labels, apps and tags"
                        onClick={() => onEdit(variable)}
                        className={actionClass}
                    >
                        Edit
                    </button>
                ) : (
                    <LockedAction reason="Editing the definition takes variables.update in every environment this variable lives in" />
                )}
                {abilities.update ? (
                    <button
                        type="button"
                        title={
                            isSet
                                ? 'Set value in this environment'
                                : `Give ${variable.key} a value in ${env}`
                        }
                        onClick={() => onSetValue(variable)}
                        className={cn(
                            actionClass,
                            !isSet && 'border-primary/40 text-primary',
                        )}
                    >
                        Set
                    </button>
                ) : (
                    <LockedAction
                        reason={`Setting a value takes variables.update in ${env}`}
                    />
                )}
                {variable.canDelete ? (
                    <button
                        type="button"
                        title="Delete this variable everywhere"
                        onClick={() => onDelete(variable)}
                        className={cn(
                            iconClass,
                            'hover:border-crit-soft hover:bg-crit-soft hover:text-crit',
                        )}
                    >
                        <X className="mx-auto size-3" strokeWidth={2} />
                    </button>
                ) : (
                    <LockedAction reason="Deleting takes variables.delete in every environment this variable lives in" />
                )}
                {isSet && (
                    <button
                        type="button"
                        title="Copy"
                        aria-label={`Copy ${variable.key}`}
                        onClick={() =>
                            publicValue !== undefined
                                ? navigator.clipboard
                                      .writeText(publicValue)
                                      .then(() =>
                                          toast.success(
                                              `Copied ${variable.key}`,
                                          ),
                                      )
                                : toast.error('Reveal required before copy')
                        }
                        className={cn(iconClass, 'hover:text-fg-2')}
                    >
                        ⧉
                    </button>
                )}
            </div>
        </div>
    );
}
