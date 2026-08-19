import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';

const appName = import.meta.env.VITE_APP_NAME || 'The Vault';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    /*
     * Only the unauthenticated screens share a layout resolved here. Everything
     * inside the application either builds its own frame from AppShell or
     * declares a `layout` function on the page - there is no starter-kit layout
     * left to fall back to.
     */
    layout: (name) => (name.startsWith('auth/') ? AuthLayout : null),
    strictMode: true,
    withApp(app) {
        return (
            <>
                {app}
                <Toaster />
            </>
        );
    },
    progress: {
        // The token, not a hex: light mode's primary is a different teal.
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
