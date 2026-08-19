import { cn } from '@/lib/utils';

export interface VersionEntry {
    version: string;
    who: string;
    when: string;
}

export function VersionHistory({
    entries,
    onRollback,
    className,
}: {
    entries: VersionEntry[];
    onRollback?: (entry: VersionEntry) => void;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-[9px] border border-line',
                className,
            )}
        >
            {entries.map((h) => (
                <div
                    key={h.version}
                    className="flex items-center gap-2.5 border-b border-line px-3 py-[9px] text-xs last:border-b-0"
                >
                    <span className="font-mono text-fg-2">{h.version}</span>
                    <span className="text-[11.5px] text-fg-3">{h.who}</span>
                    <span className="ml-auto text-[11px] text-fg-3">
                        {h.when}
                    </span>
                    {onRollback && (
                        <button
                            type="button"
                            onClick={() => onRollback(h)}
                            className="h-[22px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                        >
                            Roll back
                        </button>
                    )}
                </div>
            ))}
        </div>
    );
}
