import type { ReactNode } from 'react';
import { useState } from 'react';
import { DrawerContext } from './drawer-context';

/**
 * Full-app frame: sidebar + rounded main panel (org & project dashboards).
 *
 * Below `lg` the sidebar becomes a drawer rather than shrinking - 232px of
 * permanent navigation on a 390px phone leaves nothing for the thing you came
 * to read.
 */
export function AppShell({
    sidebar,
    children,
}: {
    sidebar: ReactNode;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <DrawerContext value={{ open, setOpen }}>
            <div className="flex h-dvh overflow-hidden bg-shell font-sans text-[13.5px] font-medium tracking-[-0.012em] text-fg antialiased">
                <aside className="hidden w-[232px] shrink-0 flex-col lg:flex">
                    {sidebar}
                </aside>

                {open && (
                    <div className="lg:hidden">
                        <button
                            type="button"
                            aria-label="Close menu"
                            onClick={() => setOpen(false)}
                            className="fixed inset-0 z-40 animate-in cursor-default bg-black/45 fade-in"
                        />
                        <aside className="fixed inset-y-0 left-0 z-50 flex w-[264px] max-w-[82vw] animate-in flex-col overflow-y-auto border-r border-line bg-shell duration-200 slide-in-from-left">
                            {sidebar}
                        </aside>
                    </div>
                )}

                <main className="m-2 flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-line bg-background lg:my-2 lg:mr-2 lg:ml-0">
                    {children}
                </main>
            </div>
        </DrawerContext>
    );
}
