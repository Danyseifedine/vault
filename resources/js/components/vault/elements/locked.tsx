import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Denied, not invisible.
 *
 * Hiding what someone may not do teaches them the feature does not exist;
 * showing it locked teaches them it exists and whose key opens it. The server
 * refuses regardless - everything here is presentation.
 */

/** The padlock, sized for inline use next to text. */
export function Padlock({ className }: { className?: string }) {
    return (
        <Lock
            aria-hidden
            className={cn('size-3 shrink-0', className)}
            strokeWidth={1.75}
        />
    );
}

const BUTTON_TONES = {
    primary:
        'border-0 bg-primary font-semibold text-primary-foreground transition-[filter] hover:brightness-108',
    outline:
        'border border-line-2 bg-transparent font-medium transition-colors hover:bg-panel-2',
} as const;

/**
 * An action button that stays on screen without its permission: disabled, a
 * padlock in front, and the reason on hover.
 */
export function GuardedButton({
    allowed,
    reason,
    onClick,
    tone = 'primary',
    className,
    children,
}: {
    allowed: boolean;
    /** Whose key opens it - "Owners and admins invite", not just "no". */
    reason: string;
    onClick: () => void;
    tone?: keyof typeof BUTTON_TONES;
    className?: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            disabled={!allowed}
            onClick={onClick}
            title={allowed ? undefined : reason}
            className={cn(
                'inline-flex h-[30px] cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[12.5px] whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-55',
                BUTTON_TONES[tone],
                className,
            )}
        >
            {!allowed && <Padlock />}
            {children}
        </button>
    );
}

/**
 * A whole card or section, visible but inert. The fieldset disables every
 * control inside without this component knowing what they are.
 *
 * The badge is the padlock alone: the reason rides in the tooltip, because a
 * screen full of sentences explaining what you cannot do reads as an argument
 * with the user.
 */
export function Locked({
    when,
    reason,
    className,
    children,
}: {
    when: boolean;
    reason: string;
    className?: string;
    children: ReactNode;
}) {
    if (!when) {
        return <>{children}</>;
    }

    return (
        <div className={cn('relative', className)} title={reason}>
            <fieldset
                disabled
                className="pointer-events-none min-w-0 border-0 p-0 opacity-55 select-none"
            >
                {children}
            </fieldset>
            <div
                aria-label={reason}
                className="absolute top-2.5 right-3 grid size-5 place-items-center rounded-full border border-line-2 bg-panel-2 text-fg-3"
            >
                <Padlock />
            </div>
        </div>
    );
}

/**
 * A single control that is on screen but refused: the padlock in place of
 * whatever it normally shows, with the reason in the tooltip. For icon-sized
 * row actions, where a disabled icon alone would read as "broken".
 */
export function LockedAction({
    reason,
    className,
}: {
    reason: string;
    className?: string;
}) {
    return (
        <span
            title={reason}
            aria-label={reason}
            className={cn(
                'grid h-[25px] w-[26px] cursor-not-allowed place-items-center rounded-md border border-line-2 bg-transparent text-fg-3 opacity-55',
                className,
            )}
        >
            <Padlock />
        </span>
    );
}

/** One locked line, for sections whose data the server does not even send. */
export function LockedNote({ children }: { children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-[11px] text-fg-3">
            <Padlock />
            {children}
        </span>
    );
}
