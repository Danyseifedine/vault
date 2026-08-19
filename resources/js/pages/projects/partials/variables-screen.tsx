import { useState } from 'react';
import { toast } from 'sonner';
import {
    CreateDialog,
    EmptyState,
    GuardedButton,
    Segmented,
} from '@/components/vault';
import { cn } from '@/lib/utils';
import { groupSchema } from '@/lib/validation/vault';
import type {
    AdminNamed,
    EnvironmentAbilities,
    ProjectVariable,
} from '../types';
import { GroupHeader } from './group-header';
import { valueFor } from './helpers';
import { ROW_GRID, VariableRow } from './variable-row';

/** Nothing granted here - every action locks rather than failing at the server. */
const NOTHING: EnvironmentAbilities = {
    create: false,
    update: false,
    rollback: false,
    reveal: false,
    export: false,
    import: false,
    policies: null,
};

export function VariablesScreen({
    variables,
    definedGroups,
    canManageGroups,
    basePath,
    env,
    abilities = NOTHING,
    query,
    sensFilter,
    onSensFilter,
    onReveal,
    onNew,
    onSetValue,
    onEdit,
    onHistory,
    onDelete,
}: {
    variables: ProjectVariable[];
    /** The project's group rows - what a header needs to rename or delete one. */
    definedGroups: AdminNamed[];
    canManageGroups: boolean;
    basePath: string;
    env: string;
    /** What the viewer may do in THIS environment. */
    abilities?: EnvironmentAbilities;
    query: string;
    sensFilter: string;
    onSensFilter: (value: string) => void;
    /** Real reveal always goes through the server; this triggers that request. */
    onReveal: (variable: ProjectVariable) => void;
    onNew: () => void;
    onSetValue: (variable: ProjectVariable) => void;
    onEdit: (variable: ProjectVariable) => void;
    onHistory: (variable: ProjectVariable) => void;
    onDelete: (variable: ProjectVariable) => void;
}) {
    /*
     * Every variable the project defines shows in every environment tab. A key
     * with no value here is listed as "not set" rather than hidden, because
     * hiding it is how a variable created a minute ago becomes unreachable.
     */
    const filtered = variables.filter(
        (v) =>
            (sensFilter === 'all' || v.sensitivity === sensFilter) &&
            (!query || v.key.toLowerCase().includes(query.toLowerCase())),
    );
    /*
     * Groups with a matching variable, in first-seen order. An empty group
     * (one created ahead of its contents) has nothing in `filtered` to derive
     * it from, so the project's defined-but-empty groups are appended - but only
     * when nothing is being searched or filtered, where a zero-row header would
     * just be noise.
     */
    const withVars = [...new Set(filtered.map((v) => v.group))];
    const activeFilter = query.trim() !== '' || sensFilter !== 'all';
    const emptyGroups = activeFilter
        ? []
        : definedGroups
              .filter((g) => g.variables === 0 && !withVars.includes(g.name))
              .map((g) => g.name);

    const groups = [...withVars, ...emptyGroups].map((name) => ({
        name,
        rows: filtered.filter((v) => v.group === name),
    }));

    /*
     * Which groups are folded away, by name. Folded rather than open, so a
     * group created after this screen mounted starts expanded like the rest.
     *
     * A search forces every group open: a key that matched but sits inside a
     * collapsed block is a search that appears to have found nothing - the
     * same rule the personal vault's list follows.
     */
    const [folded, setFolded] = useState<string[]>([]);
    const searching = query.trim() !== '';

    // Making an empty group, with no variable to justify it - a folder you can
    // set up before there is anything to file in it.
    const [newGroup, setNewGroup] = useState(false);

    const toggle = (name: string) =>
        setFolded((current) =>
            current.includes(name)
                ? current.filter((n) => n !== name)
                : [...current, name],
        );

    const missing = filtered.filter(
        (v) => valueFor(v, env) === undefined,
    ).length;

    /*
     * Copies the group as .env lines. Only what the page can already see goes
     * to the clipboard: public values in full, everything else as `KEY=` for
     * you to fill after revealing - the clipboard is not a reveal.
     */
    const copyGroup = (rows: ProjectVariable[], name: string) => {
        const lines = rows
            .map((v) => `${v.key}=${valueFor(v, env) ?? ''}`)
            .join('\n');

        navigator.clipboard
            .writeText(lines)
            .then(() =>
                toast.success(
                    `${name} copied - secret values left blank to fill in`,
                ),
            )
            .catch(() => toast.error('Could not reach the clipboard.'));
    };

    return (
        <div className="px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    Variables
                </h1>
                <div className="rounded-md border border-line px-[7px] py-[3px] font-mono text-[11.5px] text-fg-3">
                    {env}
                </div>
                {missing > 0 && (
                    <div className="text-[11.5px] text-fg-3">
                        {missing} not set in {env}
                    </div>
                )}
                <Segmented
                    className="ml-auto"
                    options={[
                        { value: 'all', label: 'All' },
                        { value: 'critical', label: 'Critical' },
                        { value: 'sensitive', label: 'Sensitive' },
                        { value: 'public', label: 'Public' },
                    ]}
                    value={sensFilter}
                    onChange={onSensFilter}
                />
                {canManageGroups && (
                    <button
                        type="button"
                        onClick={() => setNewGroup(true)}
                        className="h-[30px] shrink-0 cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] whitespace-nowrap transition-colors hover:bg-panel-2"
                    >
                        New group
                    </button>
                )}
                <GuardedButton
                    allowed={abilities.create}
                    reason={`Creating variables takes variables.create in ${env}`}
                    onClick={onNew}
                    className="shrink-0"
                >
                    New variable
                </GuardedButton>
            </div>

            {groups.length === 0 ? (
                variables.length === 0 ? (
                    <EmptyState
                        art="variable"
                        title="No variables yet"
                        description="Import a .env or add one manually - it is defined once for the project, then given a value per environment."
                    />
                ) : (
                    <EmptyState
                        art="search"
                        title="No variables match your filters"
                        description="Nothing in this project goes by that key or sensitivity."
                    />
                )
            ) : (
                <div className="overflow-x-auto overflow-y-hidden rounded-xl border border-line bg-panel">
                    <div className="min-w-[840px]">
                        <div
                            className={cn(
                                ROW_GRID,
                                'border-b border-line bg-panel-2 px-[15px] py-[9px] text-[10.5px] font-semibold tracking-[.06em] text-fg-3 uppercase',
                            )}
                        >
                            <div>Key</div>
                            <div>Value in {env}</div>
                            <div>Tags</div>
                            <div>Sensitivity</div>
                            <div className="text-right">Actions</div>
                        </div>
                        {groups.map((g) => {
                            const open = searching || !folded.includes(g.name);
                            const realGroup = definedGroups.find(
                                (row) => row.name === g.name,
                            );

                            return (
                            <div key={g.name}>
                                <GroupHeader
                                    name={g.name}
                                    count={g.rows.length}
                                    group={realGroup}
                                    ungrouped={
                                        g.name === 'ungrouped' && !realGroup
                                    }
                                    basePath={basePath}
                                    canManage={canManageGroups}
                                    open={open}
                                    onToggle={() => toggle(g.name)}
                                    onCopy={() => copyGroup(g.rows, g.name)}
                                />
                                {open && g.rows.map((v) => (
                                    <VariableRow
                                        key={v.key}
                                        variable={v}
                                        env={env}
                                        abilities={abilities}
                                        onReveal={onReveal}
                                        onSetValue={onSetValue}
                                        onEdit={onEdit}
                                        onHistory={onHistory}
                                        onDelete={onDelete}
                                    />
                                ))}
                            </div>
                            );
                        })}
                    </div>
                </div>
            )}
            <div className="mt-2.5 text-[11.5px] text-fg-3">
                Values are never sent to the browser until revealed. Each reveal
                is checked server-side and written to the audit log.
            </div>

            <CreateDialog
                open={newGroup}
                onOpenChange={setNewGroup}
                title="New group"
                description="A folder for related variables. It can sit empty until you file things into it."
                label="Group name"
                placeholder="Database"
                schema={groupSchema}
                action={`${basePath}/groups`}
            />
        </div>
    );
}
