import { ChevronRight, FileText, KeyRound, X } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { LockedAction, PreviewPanel, PreviewToggle } from '@/components/vault';
import type { PreviewPayload } from '@/components/vault';
import { cn } from '@/lib/utils';
import { sectionKey } from '@/lib/vault-sections';
import type { VaultSection } from '@/lib/vault-sections';
import type { SharedItem } from '../types';

/**
 * The shared vault's contents, grouped and collapsible - the same shape the
 * personal vault uses, because a wall of rows reads no better because the
 * credentials belong to a team.
 *
 * What differs is the gate in front of every read: a personal item is yours,
 * a shared one costs `shared.reveal` and a PIN every single time, files
 * included. So a file here opens a PIN dialog rather than following a link.
 */

const actionButton =
    'h-[26px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11.5px] transition-colors';

function ItemRow({
    item,
    shown,
    preview,
    canReveal,
    canManage,
    busy,
    onReveal,
    onTogglePreview,
    onDownload,
    onEdit,
    onRemove,
}: {
    item: SharedItem;
    shown?: string;
    preview: PreviewPayload | null | undefined;
    canReveal: boolean;
    canManage: boolean;
    busy: boolean;
    onReveal: () => void;
    onTogglePreview: () => void;
    onDownload: () => void;
    onEdit: () => void;
    onRemove: () => void;
}) {
    return (
        <div className="border-b border-line px-[15px] py-2.5 last:border-b-0">
            <div className="flex items-center gap-2.5">
                <span
                    className={cn(
                        'grid size-8 shrink-0 place-items-center rounded-lg',
                        item.type === 'file'
                            ? 'bg-panel-3 text-fg-2'
                            : 'bg-primary-soft text-primary',
                    )}
                >
                    {item.type === 'file' ? (
                        <FileText className="size-4" strokeWidth={1.75} />
                    ) : (
                        <KeyRound className="size-4" strokeWidth={1.75} />
                    )}
                </span>

                <div className="min-w-0">
                    <div className="truncate text-[12.5px]">{item.name}</div>
                    {item.description && (
                        <div className="truncate text-[11px] text-fg-3">
                            {item.description}
                        </div>
                    )}
                </div>

                {shown !== undefined && (
                    <div className="ml-3 min-w-0 flex-1 truncate rounded-md border border-line-2 bg-panel-2 px-2 py-1 font-mono text-[11.5px] select-all">
                        {shown}
                    </div>
                )}

                <div className="ml-auto flex shrink-0 items-center gap-1.5">
                    <span className="mr-1 hidden text-[11px] text-fg-3 sm:inline">
                        {item.addedAt ?? ''}
                    </span>

                    {canReveal ? (
                        item.type === 'file' ? (
                            <>
                                <PreviewToggle
                                    open={preview !== undefined}
                                    onToggle={onTogglePreview}
                                />
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={onDownload}
                                    className={cn(
                                        actionButton,
                                        'text-fg-2 hover:bg-panel-3 hover:text-fg',
                                    )}
                                >
                                    Download
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={onReveal}
                                className={cn(
                                    actionButton,
                                    'text-primary hover:bg-panel-3',
                                )}
                            >
                                {shown === undefined ? 'Reveal' : 'Hide'}
                            </button>
                        )
                    ) : (
                        <LockedAction reason="Reading a shared item takes the shared.reveal permission" />
                    )}

                    {canManage ? (
                        <>
                            <button
                                type="button"
                                onClick={onEdit}
                                className={cn(
                                    actionButton,
                                    'text-fg-2 hover:bg-panel-3 hover:text-fg',
                                )}
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                onClick={onRemove}
                                aria-label={`Delete ${item.name}`}
                                className={cn(
                                    actionButton,
                                    'w-[26px] px-0 text-center text-fg-2 hover:border-crit-soft hover:bg-crit-soft hover:text-crit',
                                )}
                            >
                                <X className="mx-auto size-3" strokeWidth={2} />
                            </button>
                        </>
                    ) : (
                        <LockedAction reason="Changing the shared vault takes the shared.manage permission" />
                    )}
                </div>
            </div>

            {preview !== undefined && (
                <PreviewPanel
                    name={item.name}
                    payload={preview}
                    className="mt-2.5"
                />
            )}
        </div>
    );
}

export function SharedList({
    sections,
    shown,
    preview,
    canReveal,
    canManage,
    busy,
    forceOpen,
    onReveal,
    onTogglePreview,
    onDownload,
    onEdit,
    onRemove,
    onRemoveGroup,
}: {
    sections: VaultSection<SharedItem>[];
    shown: { id: number; value: string } | null;
    /** The open preview, if any: its id and whatever has arrived so far. */
    preview: { id: number; payload: PreviewPayload | null } | null;
    canReveal: boolean;
    canManage: boolean;
    busy: boolean;
    /** A search is running, so nothing may stay folded away. */
    forceOpen: boolean;
    onReveal: (item: SharedItem) => void;
    onTogglePreview: (item: SharedItem) => void;
    onDownload: (item: SharedItem) => void;
    onEdit: (item: SharedItem) => void;
    onRemove: (item: SharedItem) => void;
    onRemoveGroup: (group: { id: number; name: string }) => void;
}) {
    const [folded, setFolded] = useState<string[]>([]);

    return (
        <div className="flex flex-col gap-3.5">
            {sections.map((section) => {
                const key = sectionKey(section.id);
                const open = forceOpen || !folded.includes(key);

                return (
                    <Collapsible
                        key={key}
                        open={open}
                        onOpenChange={(next) =>
                            setFolded((current) =>
                                next
                                    ? current.filter((id) => id !== key)
                                    : [...current, key],
                            )
                        }
                        className="overflow-hidden rounded-xl border border-line bg-panel"
                    >
                        <div className="flex items-center gap-2 px-[15px] py-3">
                            <CollapsibleTrigger
                                disabled={forceOpen}
                                className="flex min-w-0 cursor-pointer items-center gap-2 rounded-md bg-transparent text-left disabled:cursor-default"
                            >
                                <ChevronRight
                                    className={cn(
                                        'size-3.5 shrink-0 text-fg-3 transition-transform duration-150',
                                        open && 'rotate-90',
                                    )}
                                    strokeWidth={2}
                                />
                                <span className="truncate text-[13px] font-semibold">
                                    {section.name}
                                </span>
                                <span className="shrink-0 text-[11.5px] text-fg-3">
                                    {section.rows.length} item
                                    {section.rows.length === 1 ? '' : 's'}
                                </span>
                            </CollapsibleTrigger>

                            {section.id !== null && canManage && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onRemoveGroup({
                                            id: section.id as number,
                                            name: section.name,
                                        })
                                    }
                                    className="ml-auto h-[23px] shrink-0 cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:border-crit-soft hover:bg-crit-soft hover:text-crit"
                                >
                                    Delete group
                                </button>
                            )}
                        </div>

                        <CollapsibleContent className="border-t border-line">
                            {section.rows.length === 0 ? (
                                <div className="px-[15px] py-3 text-[11.5px] text-fg-3">
                                    Nothing filed here yet.
                                </div>
                            ) : (
                                section.rows.map((item) => (
                                    <ItemRow
                                        key={item.id}
                                        item={item}
                                        shown={
                                            shown?.id === item.id
                                                ? shown.value
                                                : undefined
                                        }
                                        preview={
                                            preview?.id === item.id
                                                ? preview.payload
                                                : undefined
                                        }
                                        canReveal={canReveal}
                                        canManage={canManage}
                                        busy={busy}
                                        onReveal={() => onReveal(item)}
                                        onTogglePreview={() =>
                                            onTogglePreview(item)
                                        }
                                        onDownload={() => onDownload(item)}
                                        onEdit={() => onEdit(item)}
                                        onRemove={() => onRemove(item)}
                                    />
                                ))
                            )}
                        </CollapsibleContent>
                    </Collapsible>
                );
            })}
        </div>
    );
}
