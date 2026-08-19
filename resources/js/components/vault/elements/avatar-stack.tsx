import { cn } from '@/lib/utils';

const AVATAR_TONES = [
    'bg-primary-soft text-primary',
    'bg-sens-soft text-sens',
    'bg-pub-soft text-pub',
    'bg-crit-soft text-crit',
];

/** Overlapping initials avatars with a +N overflow chip. */
export function AvatarStack({
    names,
    max = 3,
    className,
}: {
    names: string[];
    max?: number;
    className?: string;
}) {
    const shown = names.slice(0, max);
    const rest = names.length - shown.length;
    const initials = (n: string) =>
        n
            .split(/\s+/)
            .map((w) => w[0])
            .join('')
            .slice(0, 2)
            .toUpperCase();

    return (
        <span className={cn('flex', className)}>
            {shown.map((n, i) => (
                <span
                    key={n}
                    title={n}
                    className={cn(
                        'grid size-[26px] place-items-center rounded-full text-[10px] font-semibold',
                        AVATAR_TONES[i % AVATAR_TONES.length],
                        i > 0 && '-ml-[7px] shadow-[0_0_0_2px_var(--panel)]',
                    )}
                >
                    {initials(n)}
                </span>
            ))}
            {rest > 0 && (
                <span className="-ml-[7px] grid size-[26px] place-items-center rounded-full bg-panel-3 text-[10px] text-fg-2 shadow-[0_0_0_2px_var(--panel)]">
                    +{rest}
                </span>
            )}
        </span>
    );
}

/** Single initials avatar; tone picked deterministically from the initials. */
export function InitialsAvatar({
    initials,
    className,
}: {
    initials: string;
    className?: string;
}) {
    const tone =
        AVATAR_TONES[
            (initials.charCodeAt(0) + (initials.charCodeAt(1) || 0)) %
                AVATAR_TONES.length
        ];

    return (
        <span
            className={cn(
                'grid size-[26px] shrink-0 place-items-center rounded-full text-[10px] font-semibold',
                tone,
                className,
            )}
        >
            {initials}
        </span>
    );
}

export function Kbd({
    keys,
    className,
}: {
    keys: string[];
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex gap-1 font-mono text-[10.5px] text-fg-2',
                className,
            )}
        >
            {keys.map((k) => (
                <span
                    key={k}
                    className="rounded-[5px] border border-b-2 border-line-2 px-1.5 py-0.5"
                >
                    {k}
                </span>
            ))}
        </span>
    );
}
