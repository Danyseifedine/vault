import { toast } from 'sonner';
import { cn } from '@/lib/utils';

/** A read-only .env block with header context and audited copy. */
export function EnvBlock({
    title,
    lines,
    copyText,
    onCopy,
    className,
}: {
    /** Header label, e.g. "prod / core-api" */
    title: string;
    /** Display lines (masked upstream where needed) */
    lines: string[];
    /** Full text placed on the clipboard; defaults to the display lines */
    copyText?: string;
    onCopy?: () => void;
    className?: string;
}) {
    const copy = async () => {
        await navigator.clipboard.writeText(copyText ?? lines.join('\n'));
        onCopy?.();
        toast.success(`Copied ${lines.length} variables · logged`);
    };

    return (
        <div
            className={cn(
                'overflow-hidden rounded-[9px] border border-line bg-panel-2',
                className,
            )}
        >
            <div className="flex items-center border-b border-line px-[11px] py-[7px] font-mono text-[10.5px] text-fg-3">
                <span>{title}</span>
                <button
                    type="button"
                    onClick={copy}
                    className="ml-auto h-[22px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:text-fg"
                >
                    Copy
                </button>
            </div>
            <pre className="m-0 overflow-auto p-[11px] font-mono text-[11.5px] leading-[1.75] text-fg-2">
                {lines.join('\n')}
            </pre>
        </div>
    );
}
