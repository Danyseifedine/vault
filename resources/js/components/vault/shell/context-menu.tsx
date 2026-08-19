import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type MenuItem = {
    label: string;
    onSelect: () => void;
    /** Renders in `crit` and sits under a divider - deletions, mostly. */
    destructive?: boolean;
};

/**
 * A right-click menu for a card or row.
 *
 * It also opens on long-press, because a phone has no right button and a
 * feature reachable only with a mouse is a feature half the people using this
 * do not have.
 */
export function ContextMenu({
    items,
    children,
    className,
}: {
    items: MenuItem[];
    children: ReactNode;
    className?: string;
}) {
    const [at, setAt] = useState<{ x: number; y: number } | null>(null);
    // A ref, not a local: the timer has to survive the re-render that opening
    // the menu causes, or the cancel handlers clear nothing.
    const held = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        if (at === null) {
            return;
        }

        const close = () => setAt(null);

        window.addEventListener('click', close);
        window.addEventListener('scroll', close, true);
        window.addEventListener('keydown', close);

        return () => {
            window.removeEventListener('click', close);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('keydown', close);
        };
    }, [at]);

    return (
        <>
            <div
                className={className}
                onContextMenu={(event) => {
                    event.preventDefault();
                    setAt({ x: event.clientX, y: event.clientY });
                }}
                onTouchStart={(event) => {
                    const { clientX: x, clientY: y } = event.touches[0];
                    held.current = setTimeout(() => setAt({ x, y }), 500);
                }}
                onTouchEnd={() => clearTimeout(held.current)}
                onTouchMove={() => clearTimeout(held.current)}
            >
                {children}
            </div>

            {at && (
                <div
                    role="menu"
                    // Clamped so a menu opened near the right or bottom edge
                    // still lands on screen.
                    style={{
                        left: Math.min(at.x, window.innerWidth - 190),
                        top: Math.min(
                            at.y,
                            window.innerHeight - 12 - items.length * 32,
                        ),
                    }}
                    className="fixed z-50 min-w-[176px] animate-in overflow-hidden rounded-lg border border-line-2 bg-panel py-1 shadow-[var(--shadow-panel)] duration-100 zoom-in-95 fade-in"
                >
                    {items.map((item, index) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                setAt(null);
                                item.onSelect();
                            }}
                            className={cn(
                                'block w-full cursor-pointer border-0 bg-transparent px-3 py-1.5 text-left text-[12.5px] transition-colors',
                                item.destructive
                                    ? 'mt-1 border-t border-line pt-2 text-crit hover:bg-crit-soft'
                                    : 'text-fg-2 hover:bg-panel-2 hover:text-fg',
                                index === 0 && 'mt-0 border-t-0 pt-1.5',
                            )}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </>
    );
}
