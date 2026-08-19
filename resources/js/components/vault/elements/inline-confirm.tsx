import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Inline destructive confirmation, e.g. "Delete SMTP_PASSWORD?" */
export function InlineConfirm({
    message,
    confirmLabel = 'Delete',
    onConfirm,
    onCancel,
    className,
}: {
    message: ReactNode;
    confirmLabel?: string;
    onConfirm: () => void;
    onCancel: () => void;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex items-center gap-2 rounded-[9px] border border-line bg-panel-2 px-3 py-2.5',
                className,
            )}
        >
            <span className="text-[12.5px] text-fg-2">{message}</span>
            <span className="ml-auto flex gap-1.5">
                <button
                    type="button"
                    onClick={onCancel}
                    className="h-[26px] cursor-pointer rounded-[7px] border border-line-2 bg-transparent px-2.5 text-[11.5px] transition-colors hover:bg-panel-3"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    className="h-[26px] cursor-pointer rounded-[7px] border-0 bg-crit px-2.5 text-[11.5px] text-crit-foreground transition-[filter] hover:brightness-110"
                >
                    {confirmLabel}
                </button>
            </span>
        </div>
    );
}
