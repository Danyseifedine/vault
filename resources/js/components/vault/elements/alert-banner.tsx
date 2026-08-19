import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Tone = 'critical' | 'warning' | 'success' | 'info';

const TONES: Record<Tone, { box: string; title: string; dot: string }> = {
    critical: {
        box: 'border-crit-soft bg-crit-soft',
        title: 'text-crit',
        dot: 'bg-crit',
    },
    warning: {
        box: 'border-sens-soft bg-sens-soft',
        title: 'text-sens',
        dot: 'bg-sens',
    },
    success: {
        box: 'border-pub-soft bg-pub-soft',
        title: 'text-pub',
        dot: 'bg-pub',
    },
    info: { box: 'border-line bg-panel', title: 'text-fg', dot: 'bg-primary' },
};

export function AlertBanner({
    tone,
    title,
    children,
    className,
}: {
    tone: Tone;
    title: string;
    children?: ReactNode;
    className?: string;
}) {
    const t = TONES[tone];

    return (
        <div
            className={cn(
                'flex gap-2.5 rounded-[10px] border px-3.5 py-3',
                t.box,
                className,
            )}
        >
            <span
                className={cn('mt-1.5 size-1.5 shrink-0 rounded-full', t.dot)}
            />
            <div>
                <div className={cn('text-[12.5px] font-semibold', t.title)}>
                    {title}
                </div>
                {children && (
                    <div className="mt-0.5 text-xs text-fg-2">{children}</div>
                )}
            </div>
        </div>
    );
}
