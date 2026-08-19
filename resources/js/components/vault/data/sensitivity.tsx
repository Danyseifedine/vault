import { cn } from '@/lib/utils';

export type Sensitivity = 'critical' | 'sensitive' | 'public';

const SENSITIVITY: Record<
    Sensitivity,
    {
        label: string;
        hint: string;
        text: string;
        bg: string;
        dot: string;
        border: string;
    }
> = {
    critical: {
        label: 'Critical',
        hint: 'Reveal needs PIN and account password.',
        text: 'text-crit',
        bg: 'bg-crit-soft',
        dot: 'bg-crit',
        border: 'border-crit',
    },
    sensitive: {
        label: 'Sensitive',
        hint: 'Reveal needs the 4-digit PIN.',
        text: 'text-sens',
        bg: 'bg-sens-soft',
        dot: 'bg-sens',
        border: 'border-sens',
    },
    public: {
        label: 'Public-ish',
        hint: 'Reveals freely, still logged.',
        text: 'text-pub',
        bg: 'bg-pub-soft',
        dot: 'bg-pub',
        border: 'border-pub',
    },
};

export function SensitivityDot({
    level,
    size = 7,
    className,
}: {
    level: Sensitivity;
    size?: number;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-block shrink-0 rounded-[2px]',
                SENSITIVITY[level].dot,
                className,
            )}
            style={{ width: size, height: size }}
        />
    );
}

export function SensitivityBadge({
    level,
    className,
}: {
    level: Sensitivity;
    className?: string;
}) {
    const s = SENSITIVITY[level];

    return (
        <span
            className={cn(
                'inline-flex w-fit items-center justify-center rounded-full px-2 py-0.5 text-[10.5px]',
                s.text,
                s.bg,
                className,
            )}
        >
            {s.label}
        </span>
    );
}

export function SensitivityPicker({
    value,
    onChange,
    className,
}: {
    value: Sensitivity;
    onChange: (level: Sensitivity) => void;
    className?: string;
}) {
    return (
        <div className={className}>
            <div className="flex gap-2">
                {(Object.keys(SENSITIVITY) as Sensitivity[]).map((k) => {
                    const s = SENSITIVITY[k];
                    const on = value === k;

                    return (
                        <button
                            key={k}
                            type="button"
                            onClick={() => onChange(k)}
                            className={cn(
                                'inline-flex h-[30px] cursor-pointer items-center gap-[7px] rounded-lg border px-[11px] text-xs transition-colors',
                                on
                                    ? cn(s.bg, s.border, s.text)
                                    : 'border-line-2 bg-transparent text-fg-2 hover:bg-panel-2',
                            )}
                        >
                            <SensitivityDot level={k} />
                            {s.label}
                        </button>
                    );
                })}
            </div>
            <div className="mt-[7px] text-[11.5px] text-fg-3">
                {SENSITIVITY[value].hint}
            </div>
        </div>
    );
}
