import { router } from '@inertiajs/react';
import { ChevronRight, X } from 'lucide-react';
import { useState } from 'react';
import { DestructiveDialog, InlineRename } from '@/components/vault';
import { cn } from '@/lib/utils';
import { flash as go } from '@/lib/visit';
import type { AdminNamed } from '../types';

/**
 * The band above each block of variables.
 *
 * Groups are managed from here rather than from project settings: this is the
 * only screen where a group means anything, and a table of contents is edited
 * where the contents are. Renaming and deleting are locked to `groups.manage`
 * server-side; here they simply do not render.
 */
export function GroupHeader({
    name,
    count,
    group,
    ungrouped = false,
    basePath,
    canManage,
    open,
    onToggle,
    onCopy,
}: {
    name: string;
    count: number;
    /** The row behind the name - absent for "ungrouped", which is not a row. */
    group?: AdminNamed;
    /**
     * The nameless bucket of variables with no group. It has no row to rename,
     * so naming it instead adopts the loose variables into a real group.
     */
    ungrouped?: boolean;
    basePath: string;
    canManage: boolean;
    open: boolean;
    onToggle: () => void;
    onCopy: () => void;
}) {
    const editable = canManage && group !== undefined;
    const [deleting, setDeleting] = useState(false);

    return (
        <div className="flex items-center gap-2 border-b border-line bg-panel-2 px-[15px] py-[7px]">
            {/*
             * The chevron alone is the toggle, not the whole band: renaming,
             * copying and deleting all live up here too, and a header that
             * folded itself every time you reached for one of them would be
             * worse than one that never folded at all.
             *
             * No box around it. The negative margin buys a comfortable hit
             * area without the icon drawing a square in the middle of a text
             * row - and the ring is keyboard-only, so clicking does not leave
             * an outline sitting there afterwards.
             */}
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                aria-label={`${open ? 'Collapse' : 'Expand'} ${name}`}
                className="-m-1 flex shrink-0 cursor-pointer items-center rounded-sm border-0 bg-transparent p-1 text-fg-3 outline-none transition-colors hover:text-fg focus-visible:ring-2 focus-visible:ring-primary"
            >
                <ChevronRight
                    className={cn(
                        'size-3.5 transition-transform duration-150',
                        open && 'rotate-90',
                    )}
                    strokeWidth={2}
                />
            </button>
            {/*
             * Name and count share a baseline, so "App  14 variables" reads as
             * one line. Centring them instead let the smaller count and the
             * mono name drift apart - the count floated a pixel high.
             */}
            <div className="flex min-w-0 items-baseline gap-2">
                {editable ? (
                    <InlineRename
                        value={name}
                        className="truncate font-mono text-[11.5px] font-semibold text-fg-2"
                        onRename={(next) =>
                            router.patch(
                                `${basePath}/groups/${group.slug}`,
                                { name: next },
                                go('Group renamed'),
                            )
                        }
                    />
                ) : ungrouped && canManage ? (
                    // Naming the loose bucket adopts its variables into a group.
                    <InlineRename
                        value={name}
                        className="truncate font-mono text-[11.5px] font-semibold text-fg-2 italic"
                        onRename={(next) =>
                            router.post(
                                `${basePath}/groups/ungrouped`,
                                { name: next },
                                go(`Ungrouped variables moved into ${next}`),
                            )
                        }
                    />
                ) : (
                    <span className="truncate font-mono text-[11.5px] font-semibold text-fg-2">
                        {name}
                    </span>
                )}
                <span className="shrink-0 text-[10.5px] text-fg-3">
                    {count} {count === 1 ? 'variable' : 'variables'}
                </span>
            </div>
            <button
                type="button"
                title="Copy this group as .env lines - secret values stay blank"
                onClick={onCopy}
                className="ml-auto h-[21px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-3 transition-colors hover:bg-panel-3 hover:text-fg"
            >
                Copy group
            </button>
            {editable && (
                <button
                    type="button"
                    title="Delete this group - its variables stay and become ungrouped"
                    onClick={() => setDeleting(true)}
                    className="h-[21px] w-[22px] cursor-pointer rounded-md border border-line-2 bg-transparent text-fg-3 transition-colors hover:border-crit-soft hover:bg-crit-soft hover:text-crit"
                >
                    <X className="mx-auto size-3" strokeWidth={2} />
                </button>
            )}

            {editable && deleting && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setDeleting(false)}
                    title={`Delete the group ${name}?`}
                    description="The group only labels its variables; it does not hold them."
                    consequences={[
                        'Every variable in it is kept and becomes ungrouped.',
                    ]}
                    action={`${basePath}/groups/${group.slug}`}
                    buttonLabel="Delete group"
                    successMessage="Group deleted · logged"
                    onDeleted={() => setDeleting(false)}
                />
            )}
        </div>
    );
}
