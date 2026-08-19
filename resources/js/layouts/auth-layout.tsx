import { VaultLogo } from '@/components/vault';
import { cn } from '@/lib/utils';
import type { AuthLayoutProps } from '@/types';

/**
 * The shell behind every unauthenticated screen - sign-in, the reset flow, the
 * two-factor challenge, invitations and onboarding all sit in this one frame.
 *
 * The artwork fills the whole page and already carries the mark, so the card
 * deliberately has no logo above it. Below `lg` the artwork is dropped and the
 * logo takes its place: a full-bleed background is decoration on a wide screen
 * and a 1.5MB download on a phone, where the card should own the viewport anyway.
 */
export default function AuthLayout({
    title,
    description,
    wide,
    children,
}: AuthLayoutProps) {
    return (
        // `dark` sits on a wrapper rather than on the styled element itself:
        // the variant is `&:is(.dark *)`, so it colours descendants and never
        // the node carrying the class. Pinned rather than themed, because the
        // artwork behind these screens is a dark photograph and a light card
        // floating on it read as two different products. The theme switch lives
        // inside the application, where there is a session to remember it.
        <div className="dark">
            <div className="relative flex min-h-svh items-center justify-center bg-shell px-4 py-8 font-sans text-[13.5px] font-medium tracking-[-0.012em] text-fg antialiased sm:px-6 sm:py-10">
                {/*
                An <img> rather than a background-image utility: a URL inside a
                Tailwind class goes through Vite's CSS asset pipeline, which
                rewrites it against the bundle and not the web root. A `src`
                attribute is a runtime string nothing touches.
            */}
                <img
                    src="/assets/images/bg.png"
                    alt=""
                    aria-hidden
                    className="absolute inset-0 hidden size-full object-cover opacity-30 lg:block"
                />

                <div
                    className={cn(
                        'relative w-full',
                        wide ? 'max-w-[420px]' : 'max-w-[380px]',
                    )}
                >
                    <VaultLogo className="mx-auto mb-6 size-14 sm:size-16 lg:hidden" />

                    <div className="rounded-[14px] border border-line bg-panel p-5 shadow-[var(--shadow-panel)] sm:p-6">
                        {title && (
                            <h1 className="mb-1.5 text-[19px] font-semibold tracking-tight text-balance">
                                {title}
                            </h1>
                        )}
                        {description && (
                            <p className="mb-5 text-[12.5px] text-pretty text-fg-2">
                                {description}
                            </p>
                        )}
                        {children}
                    </div>

                    <div className="mt-4 text-center text-[11.5px] text-fg-3">
                        Internal · self-hosted
                    </div>
                </div>
            </div>
        </div>
    );
}
