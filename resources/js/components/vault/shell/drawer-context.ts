import { createContext } from 'react';

/**
 * Whether the sidebar is currently showing as a drawer. Only the mobile layout
 * ever sets it - on a wide screen the sidebar is simply always there.
 *
 * Shared by the frame (which owns the state), the sidebar rows (which close the
 * drawer once a destination is picked) and the header (whose hamburger opens
 * it). Deliberately absent from the barrel: pages have no business driving the
 * drawer directly.
 */
export const DrawerContext = createContext<{
    open: boolean;
    setOpen: (open: boolean) => void;
}>({
    open: false,
    setOpen: () => {},
});

/** One size and one weight for every icon in the frame. */
export const navIconClass = 'size-4 shrink-0';
