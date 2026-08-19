import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * The product mark, in one place. It stays an <img> rather than an inlined
 * <svg> so the gradients and the dial animation survive - and so a size class
 * is the only thing any caller has to think about.
 */
export function VaultLogo({
    className,
    ...props
}: Omit<ComponentProps<'img'>, 'src' | 'alt'>) {
    return (
        <img
            src="/assets/images/logo.svg"
            alt="The Vault"
            className={cn('block shrink-0', className)}
            {...props}
        />
    );
}
