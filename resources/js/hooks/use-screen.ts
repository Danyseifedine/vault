import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Which dashboard tab is on screen.
 *
 * The URL is the single source of truth, and the SERVER resolves it into the
 * page's `screen` prop - so the very first render already stands on the right
 * tab. A client that reads the URL after mounting renders the default first
 * and corrects itself in front of the user; this hook only ever starts from
 * what the server already decided.
 *
 * Switching tabs is a client-side Inertia visit: no request leaves the
 * browser, but the URL and Inertia's stored page (props included) both move,
 * so refresh, back and forward all agree with what is on screen.
 */
export function useScreen(initial: string, fallback: string) {
    const [screen, setScreen] = useState(initial);

    const select = (next: string) => {
        setScreen(next);

        const url = new URL(window.location.href);

        if (next === fallback) {
            url.searchParams.delete('screen');
        } else {
            url.searchParams.set('screen', next);
        }

        router.replace({
            url: url.pathname + url.search,
            props: (props) => ({ ...props, screen: next }),
            preserveScroll: true,
            preserveState: true,
        });
    };

    return [screen, select] as const;
}
