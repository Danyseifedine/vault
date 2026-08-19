import { cn } from '@/lib/utils';

/**
 * A short list of things someone did: "personal revealed · GitHub token · 2m".
 *
 * Used by the home dashboard (your recent actions everywhere) and the personal
 * vault (the private log). Rows carry event names and item names only - by the
 * time anything reaches this list, values are long gone.
 */

export interface TrailEntry {
    action: string;
    name: string | null;
    failed: boolean;
    when: string;
}

export function ActionTrail({
    entries,
    emptyLabel,
    className,
}: {
    entries: TrailEntry[];
    emptyLabel: string;
    className?: string;
}) {
    if (entries.length === 0) {
        return (
            <div
                className={cn(
                    'px-[15px] py-3 text-[11.5px] text-fg-3',
                    className,
                )}
            >
                {emptyLabel}
            </div>
        );
    }

    return (
        <div className={className}>
            {entries.map((entry, index) => (
                <div
                    key={index}
                    className="flex items-center gap-2 border-b border-line px-[15px] py-2 last:border-b-0"
                >
                    <span
                        className={cn(
                            'size-1.5 shrink-0 rounded-full',
                            entry.failed ? 'bg-crit' : 'bg-pub',
                        )}
                    />
                    <span className="text-[12px] text-fg-2">
                        {entry.action}
                    </span>
                    {entry.name && (
                        <span className="min-w-0 truncate font-mono text-[11px] text-fg-3">
                            {entry.name}
                        </span>
                    )}
                    <span className="ml-auto shrink-0 text-[10.5px] text-fg-3">
                        {entry.when}
                    </span>
                </div>
            ))}
        </div>
    );
}
