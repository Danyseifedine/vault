import { useState } from 'react';
import { fieldClass } from '@/components/vault';
import { cn } from '@/lib/utils';
import { namedResourceSchema } from '@/lib/validation/vault';

/**
 * The same field, sized down for dense settings rows. Derived rather than
 * re-typed, so a change to how an input looks reaches here too.
 */
export const settingsField = cn(
    fieldClass,
    'h-[30px] w-auto rounded-lg px-[9px] text-[12.5px]',
);

/** The small bordered Delete button dense settings rows share. */
export const rowDeleteClass =
    'ml-auto h-[23px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:border-crit-soft hover:bg-crit-soft hover:text-crit';

export function SettingsCard({
    title,
    hint,
    children,
}: {
    title: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-xl border border-line bg-panel">
            <div className="flex items-center gap-2 border-b border-line px-[15px] py-3">
                <div className="text-[13px] font-semibold">{title}</div>
                {hint && <div className="text-[11.5px] text-fg-3">{hint}</div>}
            </div>
            {children}
        </div>
    );
}

/** Add a named thing - environments, groups and apps all read the same. */
export function AddRow({
    placeholder,
    onAdd,
    extra,
}: {
    placeholder: string;
    onAdd: (name: string) => void;
    extra?: React.ReactNode;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState('');

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                const checked = namedResourceSchema.safeParse(name);

                if (!checked.success) {
                    setError(checked.error.issues[0]?.message ?? 'Invalid.');

                    return;
                }

                setError('');
                onAdd(checked.data);
                setName('');
            }}
            className="flex items-center gap-2 px-[15px] py-2.5"
        >
            <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={placeholder}
                className={cn(settingsField, 'flex-1', error && 'border-crit')}
            />
            {extra}
            <button
                type="submit"
                disabled={!namedResourceSchema.safeParse(name).success}
                className="h-[30px] shrink-0 cursor-pointer rounded-lg border-0 bg-primary px-3 text-[12.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Add
            </button>
        </form>
    );
}
