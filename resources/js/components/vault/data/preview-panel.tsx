import { Eye, EyeOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fileSize } from '../form/dropzone';

/**
 * The panel both previews render into, and the eye that opens them.
 *
 * `file-preview.tsx` owns WHERE the contents come from (the browser's File, or
 * the server's bounded reveal); this file only knows how to show what arrived.
 */

/** Enough to recognise a file, small enough to stay instant. Matches the API. */
export const MAX_PREVIEW_BYTES = 128 * 1024;

export type PreviewPayload =
    | { kind: 'text'; contents: string; truncated: boolean }
    | { kind: 'image'; src: string }
    | { kind: 'binary'; reason: 'not-text' | 'too-large' }
    | { kind: 'failed'; message: string };

function Note({
    children,
    divided = false,
}: {
    children: React.ReactNode;
    divided?: boolean;
}) {
    return (
        <div
            className={cn(
                'px-[11px] py-3 text-[11.5px] text-fg-3',
                divided && 'border-t border-line py-[7px] text-[11px]',
            )}
        >
            {children}
        </div>
    );
}

function Body({
    payload,
    name,
}: {
    payload: PreviewPayload | null;
    name: string;
}) {
    if (payload === null) {
        return <Note>Reading…</Note>;
    }

    if (payload.kind === 'failed') {
        return <Note>{payload.message}</Note>;
    }

    if (payload.kind === 'binary') {
        return (
            <Note>
                {payload.reason === 'too-large'
                    ? 'That image is too large to show here. It downloads exactly as it is.'
                    : 'There is nothing here to show as text. It is stored exactly as it is.'}
            </Note>
        );
    }

    if (payload.kind === 'image') {
        return (
            <img
                src={payload.src}
                alt={`Preview of ${name}`}
                className="max-h-[220px] w-full bg-panel-3 object-contain"
            />
        );
    }

    return (
        <>
            {/* wrap-anywhere lets an unbroken token (a base64 key) break, so
                its width can never force the panel wider than its container. */}
            <pre className="max-h-[220px] overflow-auto px-[11px] py-2.5 font-mono text-[11.5px] leading-[1.7] wrap-anywhere whitespace-pre-wrap select-all">
                {payload.contents}
            </pre>
            {payload.truncated && (
                <Note divided>
                    Showing the first {fileSize(MAX_PREVIEW_BYTES)} of the file.
                </Note>
            )}
        </>
    );
}

/** The panel itself: a header naming the file, then whatever it holds. */
export function PreviewPanel({
    name,
    size,
    payload,
    className,
}: {
    name: string;
    size?: string;
    payload: PreviewPayload | null;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'animate-in overflow-hidden rounded-[9px] border border-line duration-150 fade-in',
                className,
            )}
        >
            <div className="flex items-center gap-2 border-b border-line bg-panel-2 px-[11px] py-[7px] text-[11px] text-fg-3">
                <span className="truncate font-mono">{name}</span>
                {size && <span className="ml-auto shrink-0">{size}</span>}
            </div>
            <Body payload={payload} name={name} />
        </div>
    );
}

/**
 * The eye that opens a preview. It sits with the file it belongs to - in the
 * dropzone's row, or in a vault item's actions - so the caller owns whether
 * the panel is open and the panel itself renders below.
 */
export function PreviewToggle({
    open,
    onToggle,
}: {
    open: boolean;
    onToggle: () => void;
}) {
    const label = open ? 'Hide the contents' : 'Show the contents';

    return (
        <button
            type="button"
            onClick={onToggle}
            aria-label={label}
            aria-pressed={open}
            title={label}
            className={cn(
                'grid size-7 cursor-pointer place-items-center rounded-md border transition-colors',
                open
                    ? 'border-primary bg-primary-soft text-primary'
                    : 'border-line-2 bg-transparent text-fg-3 hover:bg-panel-2 hover:text-fg',
            )}
        >
            {open ? (
                <EyeOff className="size-3.5" strokeWidth={1.75} />
            ) : (
                <Eye className="size-3.5" strokeWidth={1.75} />
            )}
        </button>
    );
}
