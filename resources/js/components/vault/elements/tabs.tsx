import { cn } from '@/lib/utils';

export interface TabOption {
    value: string;
    label: string;
}

export function PillTabs({
    options,
    value,
    onChange,
    className,
}: {
    options: TabOption[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap gap-1', className)}>
            {options.map((t) => {
                const on = t.value === value;

                return (
                    <button
                        key={t.value}
                        type="button"
                        onClick={() => onChange(t.value)}
                        className={cn(
                            'h-7 cursor-pointer rounded-[7px] border-0 px-2.5 text-[12.5px] transition-colors',
                            on
                                ? 'bg-panel-3 font-semibold text-fg'
                                : 'bg-transparent text-fg-2 hover:bg-panel-2 hover:text-fg',
                        )}
                    >
                        {t.label}
                    </button>
                );
            })}
        </div>
    );
}

export function UnderlineTabs({
    options,
    value,
    onChange,
    className,
}: {
    options: TabOption[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap gap-4 border-b border-line',
                className,
            )}
        >
            {options.map((t) => {
                const on = t.value === value;

                return (
                    <button
                        key={t.value}
                        type="button"
                        onClick={() => onChange(t.value)}
                        className={cn(
                            '-mb-px cursor-pointer border-0 border-b-2 bg-transparent pb-2 text-[12.5px] transition-colors',
                            on
                                ? 'border-primary font-semibold text-fg'
                                : 'border-transparent text-fg-2 hover:text-fg',
                        )}
                    >
                        {t.label}
                    </button>
                );
            })}
        </div>
    );
}
