import { Link } from '@inertiajs/react';
import { LogOut, Moon, Settings, Sun } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { use } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { DrawerContext, navIconClass } from './drawer-context';
import { VaultLogo } from './logo';

/**
 * Sidebar top row: logo + product name + small mono context label. The mark is
 * a link home - from anywhere, however deep.
 */
export function SidebarBrand({ context }: { context?: string }) {
    const { setOpen } = use(DrawerContext);

    return (
        <div className="flex h-12 items-center gap-2 px-3.5">
            <Link
                href={dashboard()}
                onClick={() => setOpen(false)}
                title="Home"
                className="flex items-center gap-2 text-inherit"
            >
                {/* <VaultLogo className="size-12" /> */}
                <span className="text-[13.5px] font-semibold">MY VAULT</span>
            </Link>
            {context && (
                <div className="ml-auto font-mono text-[10.5px] text-fg-3">
                    {context}
                </div>
            )}
        </div>
    );
}

export interface NavItem {
    id: string;
    label: string;
    /**
     * A lucide icon, not a character. The geometric glyphs this used to carry
     * (◈ ◍ ⚿ ◐) are not in Geist, so every one of them fell back to whatever
     * the operating system had: different weights, different sizes, and on
     * Windows a box. An icon set draws at the size you ask for.
     */
    icon: LucideIcon;
    badge?: string;
    badgeTone?: 'muted' | 'crit';
}

export function SidebarNav({
    items,
    active,
    onSelect,
}: {
    items: NavItem[];
    active: string;
    onSelect: (id: string) => void;
}) {
    const { setOpen } = use(DrawerContext);

    return (
        <nav className="flex flex-col gap-px px-2.5 py-1.5">
            {items.map((item) => {
                const on = item.id === active;

                return (
                    <button
                        key={item.id}
                        type="button"
                        onClick={() => {
                            onSelect(item.id);
                            // Picking a destination is the end of the drawer's job.
                            setOpen(false);
                        }}
                        className={cn(
                            'flex w-full cursor-pointer items-center gap-2 rounded-[7px] border-0 px-2 py-1.5 text-left text-[12.5px] transition-colors',
                            on
                                ? 'bg-panel-3 font-semibold text-fg'
                                : 'bg-transparent text-fg-2 hover:bg-panel-2',
                        )}
                    >
                        <item.icon
                            className={cn(
                                navIconClass,
                                on ? 'text-fg' : 'text-fg-3',
                            )}
                            strokeWidth={1.75}
                        />
                        <span>{item.label}</span>
                        {item.badge && (
                            <span
                                className={cn(
                                    'ml-auto font-mono text-[10px]',
                                    item.badgeTone === 'crit'
                                        ? 'text-crit'
                                        : 'text-fg-3',
                                )}
                            >
                                {item.badge}
                            </span>
                        )}
                    </button>
                );
            })}
        </nav>
    );
}

export function SidebarHeading({ children }: { children: ReactNode }) {
    return (
        <div className="px-4.5 pt-4 pb-2 text-[10.5px] font-semibold tracking-[.06em] text-fg-3 uppercase">
            {children}
        </div>
    );
}

/** Mono list row for sidebars (projects / apps): dot + name + count. */
export function SidebarMonoRow({
    name,
    meta,
    dotClass = 'bg-fg-3',
    href,
}: {
    name: string;
    meta?: string;
    dotClass?: string;
    href?: string;
}) {
    const { setOpen } = use(DrawerContext);

    const inner = (
        <>
            <span className={cn('size-[5px] rounded-full', dotClass)} />
            <span className="font-mono text-[11.5px]">{name}</span>
            {meta && (
                <span className="ml-auto text-[10.5px] text-fg-3">{meta}</span>
            )}
        </>
    );
    const className =
        'flex items-center gap-2 rounded-md px-2 py-[5px] text-[12.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg';

    // A Link, not an anchor: an anchor reloads the whole application to move
    // between two pages that share this very sidebar.
    return href ? (
        <Link href={href} onClick={() => setOpen(false)} className={className}>
            {inner}
        </Link>
    ) : (
        <div className={className}>{inner}</div>
    );
}

/** Sidebar bottom: current user, account settings, theme, and the way out. */
export function SidebarFooter({
    name,
    meta,
    initials,
}: {
    name: string;
    meta: string;
    initials: string;
}) {
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const iconButton =
        'grid size-[30px] shrink-0 cursor-pointer place-items-center rounded-lg border border-line bg-transparent text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg';

    return (
        <div className="mt-auto flex items-center gap-2 border-t border-line p-2.5">
            <div className="grid size-7 shrink-0 place-items-center rounded-full bg-primary-soft text-[10.5px] font-semibold text-primary">
                {initials}
            </div>
            <div className="min-w-0 leading-tight">
                <div className="truncate text-xs font-semibold">{name}</div>
                <div className="truncate text-[10.5px] text-fg-3">{meta}</div>
            </div>

            <div className="ml-auto flex items-center gap-1">
                <Link
                    href={editProfile()}
                    title="Account settings"
                    className={iconButton}
                >
                    <Settings className={navIconClass} strokeWidth={1.75} />
                </Link>
                <button
                    type="button"
                    onClick={() =>
                        updateAppearance(
                            resolvedAppearance === 'dark' ? 'light' : 'dark',
                        )
                    }
                    aria-label="Toggle theme"
                    className={iconButton}
                >
                    {resolvedAppearance === 'dark' ? (
                        <Sun className={navIconClass} strokeWidth={1.75} />
                    ) : (
                        <Moon className={navIconClass} strokeWidth={1.75} />
                    )}
                </button>
                {/*
                    A POST, which the route definition already carries: signing
                    out with a GET means any image tag on any page can do it for
                    you.
                */}
                <Link
                    href={logout()}
                    as="button"
                    title="Sign out"
                    className={cn(
                        iconButton,
                        'hover:border-crit-soft hover:bg-crit-soft hover:text-crit',
                    )}
                >
                    <LogOut className={navIconClass} strokeWidth={1.75} />
                </Link>
            </div>
        </div>
    );
}
