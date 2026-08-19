import { cn } from '@/lib/utils';

/** Bare switch control (accent when on). */
function Switch({
    checked,
    onChange,
    disabled,
    label,
    className,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    label?: string;
    className?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'flex h-5 w-[34px] shrink-0 cursor-pointer rounded-full border-0 p-0.5 transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'justify-end bg-primary' : 'justify-start bg-panel-3',
                className,
            )}
        >
            <span
                className={cn(
                    'size-4 rounded-full',
                    checked ? 'bg-white' : 'bg-fg-3',
                )}
            />
        </button>
    );
}

/** A policy row: label + hint on the left, switch on the right. */
export function PolicySwitchRow({
    label,
    hint,
    checked,
    onChange,
    disabled,
    className,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <div className={cn('flex items-start gap-3', className)}>
            <div className="min-w-0 flex-1">
                <div className="text-[12.5px]">{label}</div>
                {hint && (
                    <div className="text-[11.5px] text-pretty text-fg-3">
                        {hint}
                    </div>
                )}
            </div>
            <Switch
                checked={checked}
                onChange={onChange}
                disabled={disabled}
                label={label}
            />
        </div>
    );
}
