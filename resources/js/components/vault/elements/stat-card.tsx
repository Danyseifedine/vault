import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function StatCard({
    label,
    value,
    meta,
    metaTone = 'muted',
    children,
    className,
}: {
    label: string;
    value?: string | number;
    meta?: string;
    metaTone?: 'muted' | 'crit' | 'sens' | 'pub';
    children?: ReactNode;
    className?: string;
}) {
    const tone = {
        muted: 'text-fg-3',
        crit: 'text-crit',
        sens: 'text-sens',
        pub: 'text-pub',
    }[metaTone];

    return (
        <div
            className={cn(
                'rounded-[11px] border border-line bg-panel px-[15px] py-3.5',
                className,
            )}
        >
            <div className="text-[11px] tracking-[.05em] text-fg-3 uppercase">
                {label}
            </div>
            {value !== undefined && (
                <div className="mt-2 flex items-baseline gap-[7px]">
                    <div className="font-mono text-[25px] font-semibold">
                        {value}
                    </div>
                    {meta && (
                        <div className={cn('text-[11.5px]', tone)}>{meta}</div>
                    )}
                </div>
            )}
            {children}
        </div>
    );
}

/** Sensitivity composition bar: proportional critical / sensitive / public segments. */
export function CompositionBar({
    critical,
    sensitive,
    publicish,
    className,
}: {
    critical: number;
    sensitive: number;
    publicish: number;
    className?: string;
}) {
    const total = Math.max(1, critical + sensitive + publicish);

    return (
        <div className={cn('flex items-center gap-[3px]', className)}>
            {critical > 0 && (
                <span
                    className="h-1.5 rounded-[2px] bg-crit"
                    style={{ flexGrow: critical / total }}
                />
            )}
            {sensitive > 0 && (
                <span
                    className="h-1.5 rounded-[2px] bg-sens"
                    style={{ flexGrow: sensitive / total }}
                />
            )}
            {publicish > 0 && (
                <span
                    className="h-1.5 rounded-[2px] bg-pub"
                    style={{ flexGrow: publicish / total }}
                />
            )}
        </div>
    );
}

export function ProgressThin({
    percent,
    className,
}: {
    percent: number;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'h-1.5 overflow-hidden rounded-full bg-panel-3',
                className,
            )}
        >
            <span
                className="block h-full rounded-full bg-primary transition-[width]"
                style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
            />
        </div>
    );
}
