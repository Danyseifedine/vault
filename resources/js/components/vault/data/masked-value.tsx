import { cn } from '@/lib/utils';
import { SensitivityDot } from './sensitivity';
import type { Sensitivity } from './sensitivity';

/**
 * Masked secret display. The real value is NEVER passed to this component - * it renders bullets and a reveal affordance; revealing goes through the
 * server-side reveal flow (see RevealDialog).
 */
export function MaskedValue({
    level,
    revealLabel,
    onReveal,
    value,
    className,
}: {
    level: Sensitivity;
    /** Label of the reveal action, e.g. "Reveal", "PIN", "PIN + pw" */
    revealLabel?: string;
    onReveal?: () => void;
    /** Present only after a successful server reveal */
    value?: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex h-[34px] items-center gap-2 rounded-lg border border-line bg-panel-2 py-0 pr-2 pl-[11px]',
                className,
            )}
        >
            <SensitivityDot level={level} />
            {value !== undefined ? (
                <span className="flex-1 truncate font-mono text-[12.5px]">
                    {value}
                </span>
            ) : (
                <span className="flex-1 font-mono text-[12.5px] text-fg-2 select-none">
                    ••••••••••••••••
                </span>
            )}
            {onReveal && value === undefined && (
                <button
                    type="button"
                    onClick={onReveal}
                    className={cn(
                        'h-6 cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11px] transition-colors hover:bg-panel-3',
                        level === 'critical'
                            ? 'text-crit'
                            : 'text-fg-2 hover:text-fg',
                    )}
                >
                    {revealLabel ?? 'Reveal'}
                </button>
            )}
        </div>
    );
}
