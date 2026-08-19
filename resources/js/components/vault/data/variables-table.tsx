import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { SensitivityBadge, SensitivityDot } from './sensitivity';
import type { Sensitivity } from './sensitivity';

export interface VariableRowData {
    key: string;
    sensitivity: Sensitivity;
}

/** Compact variables table: key + sensitivity + action, with pagination footer. */
export function VariablesTable({
    title = 'Variables',
    rows,
    onReveal,
    footer,
    className,
}: {
    title?: string;
    rows: VariableRowData[];
    onReveal?: (row: VariableRowData) => void;
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-[11px] border border-line bg-panel',
                className,
            )}
        >
            <div className="flex items-center gap-2 border-b border-line px-[15px] py-[11px]">
                <span className="text-[12.5px] font-semibold">{title}</span>
            </div>
            <div className="grid grid-cols-[minmax(0,1fr)_92px_84px] gap-2.5 border-b border-line bg-panel-2 px-[15px] py-2 text-[10.5px] tracking-[.06em] text-fg-3 uppercase">
                <div>Key</div>
                <div>Sensitivity</div>
                <div className="text-right">Action</div>
            </div>
            {rows.map((r) => (
                <div
                    key={r.key}
                    className="grid min-h-10 grid-cols-[minmax(0,1fr)_92px_84px] items-center gap-2.5 border-b border-line px-[15px] transition-colors hover:bg-panel-2"
                >
                    <div className="flex min-w-0 items-center gap-2">
                        <SensitivityDot level={r.sensitivity} />
                        <span className="truncate font-mono text-xs">
                            {r.key}
                        </span>
                    </div>
                    <SensitivityBadge level={r.sensitivity} />
                    <div className="text-right">
                        {onReveal && (
                            <button
                                type="button"
                                onClick={() => onReveal(r)}
                                className="h-6 cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11px] text-fg-2 transition-colors hover:bg-panel-3 hover:text-fg"
                            >
                                Reveal
                            </button>
                        )}
                    </div>
                </div>
            ))}
            {footer && (
                <div className="flex items-center gap-2 px-[15px] py-2.5">
                    {footer}
                </div>
            )}
        </div>
    );
}

export function TablePagination({
    label,
    onPrev,
    onNext,
    hasPrev = true,
    hasNext = true,
}: {
    label: string;
    onPrev?: () => void;
    onNext?: () => void;
    hasPrev?: boolean;
    hasNext?: boolean;
}) {
    return (
        <>
            <span className="text-[11.5px] text-fg-3">{label}</span>
            <span className="ml-auto flex gap-[5px]">
                <button
                    type="button"
                    onClick={onPrev}
                    disabled={!hasPrev}
                    className="size-[26px] cursor-pointer rounded-[7px] border border-line-2 bg-transparent text-fg-3 transition-colors hover:text-fg disabled:cursor-not-allowed disabled:opacity-40"
                >
                    ‹
                </button>
                <button
                    type="button"
                    onClick={onNext}
                    disabled={!hasNext}
                    className="size-[26px] cursor-pointer rounded-[7px] border border-line-2 bg-transparent text-fg transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                >
                    ›
                </button>
            </span>
        </>
    );
}
