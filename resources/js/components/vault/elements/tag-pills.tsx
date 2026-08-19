import { cn } from '@/lib/utils';

/** Toggleable free-tag pills (informal tags: legacy, rotate-soon, …). */
export function TagPills({
    options,
    selected,
    onChange,
    className,
}: {
    options: string[];
    selected: string[];
    onChange: (selected: string[]) => void;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap gap-1.5', className)}>
            {options.map((t) => {
                const on = selected.includes(t);

                return (
                    <button
                        key={t}
                        type="button"
                        aria-pressed={on}
                        onClick={() =>
                            onChange(
                                on
                                    ? selected.filter((x) => x !== t)
                                    : [...selected, t],
                            )
                        }
                        className={cn(
                            'h-[26px] cursor-pointer rounded-full border px-2.5 font-mono text-[11px] transition-colors',
                            on
                                ? 'border-primary bg-primary-soft text-primary'
                                : 'border-line-2 bg-transparent text-fg-2 hover:bg-panel-2',
                        )}
                    >
                        {t}
                    </button>
                );
            })}
        </div>
    );
}

/** Static tag chip (mono, bordered) for display contexts. */
export function TagChip({
    label,
    className,
}: {
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'rounded-[5px] border border-line-2 px-1.5 py-0.5 font-mono text-[10px] text-fg-2',
                className,
            )}
        >
            {label}
        </span>
    );
}
