import { useState } from 'react';
import { cn } from '@/lib/utils';
import { namedResourceSchema } from '@/lib/validation/vault';

/**
 * Rename in place: a label until you click it, an input while you type.
 *
 * Used for environments, groups and apps, which all rename the same way - * a name in, a slug rebuilt server-side, the URL no longer lying.
 */
export function InlineRename({
    value,
    onRename,
    className,
}: {
    value: string;
    onRename: (next: string) => void;
    className?: string;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(value);

    const commit = () => {
        const checked = namedResourceSchema.safeParse(draft);

        setEditing(false);

        // Same 1-60 rule the server enforces: a name it would refuse never
        // leaves the field. Anything invalid (empty, too long) or unchanged
        // snaps back to what was there.
        if (checked.success && checked.data !== value) {
            onRename(checked.data);
        } else {
            setDraft(value);
        }
    };

    if (!editing) {
        return (
            <button
                type="button"
                title="Click to rename"
                onClick={() => {
                    setDraft(value);
                    setEditing(true);
                }}
                className={cn(
                    'cursor-pointer border-0 bg-transparent p-0 text-left hover:underline',
                    className,
                )}
            >
                {value}
            </button>
        );
    }

    return (
        <input
            maxLength={60}
            autoFocus
            // Select the current name on focus, so a rename can be typed
            // straight over it instead of landing after it.
            onFocus={(e) => e.currentTarget.select()}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    commit();
                }

                if (e.key === 'Escape') {
                    setDraft(value);
                    setEditing(false);
                }
            }}
            className={cn(
                'h-[26px] w-[150px] rounded-lg border border-line-2 bg-panel-2 px-2 text-[12.5px] outline-none focus:border-primary',
                className,
            )}
        />
    );
}
