import { X } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/** Multi-select token field (e.g. app assignments). Enter adds, Backspace removes the last. */
export function TokenField({
    tokens,
    onChange,
    placeholder = 'add…',
    validate,
    className,
}: {
    tokens: string[];
    onChange: (tokens: string[]) => void;
    placeholder?: string;
    /** Optional filter for new entries; return false to reject */
    validate?: (token: string) => boolean;
    className?: string;
}) {
    const [draft, setDraft] = useState('');

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const v = draft.trim();

            if (!v || (validate && !validate(v))) {
                return;
            }

            if (!tokens.includes(v)) {
                onChange([...tokens, v]);
            }

            setDraft('');
        } else if (e.key === 'Backspace' && draft === '') {
            onChange(tokens.slice(0, -1));
        }
    };

    return (
        <div
            className={cn(
                'flex min-h-[34px] flex-wrap items-center gap-1.5 rounded-lg border border-line-2 bg-panel-2 px-2 py-[5px] focus-within:border-primary',
                className,
            )}
        >
            {tokens.map((t) => (
                <span
                    key={t}
                    className="inline-flex h-[22px] items-center gap-1.5 rounded-md bg-primary-soft py-0 pr-1 pl-2 font-mono text-[11px] text-primary"
                >
                    {t}
                    <button
                        type="button"
                        aria-label={`Remove ${t}`}
                        onClick={() => onChange(tokens.filter((x) => x !== t))}
                        className="size-[15px] cursor-pointer rounded border-0 bg-transparent text-[10px] leading-none text-primary hover:bg-panel-3"
                    >
                        <X className="mx-auto size-2.5" strokeWidth={2} />
                    </button>
                </span>
            ))}
            <input
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={onKeyDown}
                placeholder={placeholder}
                className="h-[22px] min-w-[70px] flex-1 border-0 bg-transparent text-xs outline-none placeholder:text-fg-3"
            />
        </div>
    );
}
