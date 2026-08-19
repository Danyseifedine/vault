import type { ReactNode } from 'react';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    title?: string;
    description?: string;
    /** Wider card for screens that carry more than a form - invitations, onboarding. */
    wide?: boolean;
};
