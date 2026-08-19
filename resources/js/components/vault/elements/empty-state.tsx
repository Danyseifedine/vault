import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The illustrations that live in `public/assets/images/empty`.
 *
 * Each one draws inside roughly 40% of a 1254px square, so most of the file is
 * transparent padding. Rendered as-is the mark looks small and pushed far from
 * the text below it. The `zoom` scales that padding out of view; the numbers
 * come from each file's actual path bounds, so the artwork lands just inside
 * its box without clipping.
 */
const ART = {
    org: { src: '/assets/images/empty/empty-org.svg', zoom: 'scale-[2.4]' },
    vault: { src: '/assets/images/empty/empty-vault.svg', zoom: 'scale-[2.1]' },
    search: {
        src: '/assets/images/empty/empty-search.svg',
        zoom: 'scale-[2.4]',
    },
    variable: {
        src: '/assets/images/empty/no-variable.svg',
        zoom: 'scale-[1.85]',
    },
    activity: {
        src: '/assets/images/empty/no-activity.svg',
        zoom: 'scale-[1.6]',
    },
    // These two are drawn nearly edge to edge on non-square canvases, unlike
    // the others - hence the milder zooms and the object-contain on the img.
    tag: { src: '/assets/images/empty/empty-tag.svg', zoom: 'scale-[1.15]' },
    project: {
        src: '/assets/images/empty/empty-project.svg',
        zoom: 'scale-[1.15]',
    },
} as const;

export function EmptyState({
    title,
    description,
    action,
    art,
    icon,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    /**
     * Show an illustration and fill the height available, instead of sitting in
     * a small dashed box. For the screens where "there is nothing here yet" is
     * the whole page rather than one section of it.
     */
    art?: keyof typeof ART;
    /**
     * The mark for the compact (non-art) variant, shown centered above the
     * text. Defaults to a neutral inbox so a section empty is never a bare
     * strip of text hugging the top of its card - override it with something
     * that fits the place (a key, a tag, a person).
     */
    icon?: ReactNode;
    className?: string;
}) {
    if (art) {
        return (
            <div
                className={cn(
                    'flex min-h-[380px] grow flex-col items-center justify-center px-6 py-10 text-center',
                    className,
                )}
            >
                <div className="mb-3 aspect-square w-[200px] max-w-[55%] overflow-hidden">
                    <img
                        src={ART[art].src}
                        alt=""
                        aria-hidden
                        className={cn(
                            'size-full object-contain opacity-90',
                            ART[art].zoom,
                        )}
                    />
                </div>
                <div className="mb-1.5 text-[15px] font-semibold tracking-tight">
                    {title}
                </div>
                {description && (
                    <p className="max-w-[380px] text-[12.5px] text-pretty text-fg-2">
                        {description}
                    </p>
                )}
                {action && (
                    <div className="mt-4 flex justify-center">{action}</div>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex min-h-[150px] flex-col items-center justify-center gap-2.5 rounded-[10px] border border-dashed border-line-2 px-5 py-8 text-center',
                className,
            )}
        >
            <div className="grid size-9 shrink-0 place-items-center rounded-full bg-panel-2 text-fg-3">
                {icon ?? <Inbox className="size-4" strokeWidth={1.75} />}
            </div>
            <div>
                <div className="text-[12.5px] font-medium">{title}</div>
                {description && (
                    <div className="mt-0.5 text-[11.5px] text-fg-3">
                        {description}
                    </div>
                )}
            </div>
            {action && (
                <div className="mt-0.5 flex justify-center">{action}</div>
            )}
        </div>
    );
}
