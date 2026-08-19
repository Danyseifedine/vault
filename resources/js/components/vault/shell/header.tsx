import { Menu, Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { use } from 'react';
import { cn } from '@/lib/utils';
import { DrawerContext, navIconClass } from './drawer-context';

/**
 * Main-panel header: breadcrumb left, actions right - and, below `lg`, the only
 * way to reach the sidebar. It wraps rather than scrolls, because a row of
 * actions squeezed off the right edge of a phone is a row of actions nobody has.
 */
export function ShellHeader({
    crumb,
    active,
    children,
}: {
    crumb: string;
    active: string;
    children?: ReactNode;
}) {
    const { setOpen } = use(DrawerContext);

    return (
        <header className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-3 py-2.5 sm:px-4 lg:h-[52px] lg:flex-nowrap lg:py-0">
            <button
                type="button"
                aria-label="Open menu"
                onClick={() => setOpen(true)}
                className="grid size-8 shrink-0 cursor-pointer place-items-center rounded-lg border border-line-2 bg-transparent text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg lg:hidden"
            >
                <Menu className={navIconClass} strokeWidth={1.75} />
            </button>

            <div className="flex min-w-0 items-center gap-[7px] text-[12.5px] text-fg-3">
                <span className="truncate">{crumb}</span>
                <span className="opacity-50">/</span>
                <span className="truncate font-semibold text-fg">{active}</span>
            </div>
            {children}
        </header>
    );
}

/** Compact search input, in a header or in a page's own toolbar. */
export function HeaderSearch({
    value,
    onChange,
    placeholder,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex h-[30px] min-w-0 flex-initial items-center gap-[7px] rounded-lg border border-line bg-panel-2 px-2.5 text-xs text-fg-3',
                className,
            )}
        >
            <Search className="size-3.5 shrink-0" strokeWidth={1.75} />
            <input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full min-w-0 border-0 bg-transparent text-xs text-fg outline-none placeholder:text-fg-3"
            />
        </div>
    );
}
