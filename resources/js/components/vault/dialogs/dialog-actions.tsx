import { cn } from '@/lib/utils';

/**
 * The Cancel + submit row every dialog form ends with.
 *
 * It existed as eight hand-copied pairs of buttons before this file - the same
 * two elements, the same classes, drifting one utility at a time.
 * The submit is a real `type="submit"`, so pressing Enter in the form works.
 */
export function DialogActions({
    onCancel,
    label,
    busyLabel,
    busy = false,
    disabled = false,
    tone = 'primary',
    size = 'md',
    className,
}: {
    onCancel: () => void;
    label: string;
    /** Shown while busy - "Saving…", "Deleting…". Defaults to the label. */
    busyLabel?: string;
    busy?: boolean;
    /** Kept un-pressable for a reason other than being busy (a confirm word). */
    disabled?: boolean;
    tone?: 'primary' | 'danger';
    /** md matches the 34px dialog forms, lg the 38px ones. */
    size?: 'md' | 'lg';
    /** Forms that space their children with `gap` pass `mt-0` here. */
    className?: string;
}) {
    const shape =
        size === 'lg' ? 'h-[38px] rounded-[9px]' : 'h-[34px] rounded-lg';

    return (
        <div className={cn('mt-3.5 flex gap-2', className)}>
            <button
                type="button"
                onClick={onCancel}
                className={cn(
                    shape,
                    'flex-1 cursor-pointer border border-line-2 bg-transparent text-[12.5px] transition-colors hover:bg-panel-2',
                )}
            >
                Cancel
            </button>
            <button
                type="submit"
                disabled={busy || disabled}
                className={cn(
                    shape,
                    'flex-1 cursor-pointer border-0 text-[12.5px] font-semibold',
                    // Progress only while actually submitting; a form that is
                    // just not valid yet says "not allowed", not "loading".
                    busy
                        ? 'disabled:cursor-progress'
                        : 'disabled:cursor-not-allowed',
                    tone === 'danger'
                        ? 'bg-crit text-crit-foreground transition-[filter] hover:brightness-110 disabled:opacity-40'
                        : 'bg-primary text-primary-foreground transition-[filter] hover:brightness-108 disabled:opacity-85',
                )}
            >
                {busy ? (busyLabel ?? label) : label}
            </button>
        </div>
    );
}
