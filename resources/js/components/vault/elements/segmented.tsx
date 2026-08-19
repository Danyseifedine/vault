import { cn } from '@/lib/utils';

/** Segmented control (e.g. dev / staging / prod). */
export function Segmented({
    options,
    value,
    onChange,
    className,
}: {
    options: { value: string; label: string }[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'inline-flex gap-0.5 rounded-lg border border-line bg-panel-2 p-0.5',
                className,
            )}
        >
            {options.map((o) => {
                const on = o.value === value;

                return (
                    <button
                        key={o.value}
                        type="button"
                        aria-pressed={on}
                        onClick={() => onChange(o.value)}
                        className={cn(
                            'h-6 cursor-pointer rounded-md border-0 px-[11px] text-xs transition-colors',
                            on
                                ? 'bg-panel text-fg shadow-sm'
                                : 'bg-transparent text-fg-3 hover:text-fg-2',
                        )}
                    >
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}
