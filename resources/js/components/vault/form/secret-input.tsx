import { useState } from 'react';
import { cn } from '@/lib/utils';

/** Input for entering/editing a secret value, with a show/hide toggle. */
export function SecretInput({
    value,
    onChange,
    placeholder,
    className,
    id,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    className?: string;
    id?: string;
}) {
    const [shown, setShown] = useState(false);

    return (
        <div
            className={cn(
                'flex h-[34px] items-center gap-2 rounded-lg border border-line-2 bg-panel-2 py-0 pr-2 pl-[11px] focus-within:border-primary',
                className,
            )}
        >
            <input
                id={id}
                type={shown ? 'text' : 'password'}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                autoComplete="off"
                spellCheck={false}
                className="min-w-0 flex-1 border-0 bg-transparent font-mono text-[12.5px] outline-none placeholder:text-fg-3"
            />
            <button
                type="button"
                onClick={() => setShown((s) => !s)}
                className="h-6 cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11px] text-fg-2 transition-colors hover:bg-panel-3 hover:text-fg"
            >
                {shown ? 'Hide' : 'Show'}
            </button>
        </div>
    );
}
