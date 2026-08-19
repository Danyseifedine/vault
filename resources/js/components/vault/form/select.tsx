import { Command, useCommandState } from 'cmdk';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { FieldError, RequiredMark } from './form';

/**
 * The one dropdown in the product.
 *
 * Native `<select>` cannot be styled: it renders the operating system's list,
 * which meant every choice in the app looked like Windows while everything
 * around it looked like the design system. This is the design system's
 * "Selects & choice" card, built on cmdk (filtering, keyboard navigation,
 * typeahead) inside a Radix popover (portal, escape, outside-click, collision).
 *
 * The design shows a Select and a Combobox; they are the same control, and the
 * only difference is whether the filter row is there. So there is one component
 * and a `searchable` prop, which turns itself on once the list is long enough
 * to be worth filtering - a variant, not a second component.
 */

export type SelectOption = {
    value: string;
    label: string;
    /** Right-aligned detail: "3 vars", "owner only". */
    meta?: string;
    /** Heading this option sits under - the native `<optgroup>`. */
    group?: string;
    disabled?: boolean;
};

/** Matches the input sizes in `form.tsx`, so a select lines up with a field. */
const SIZES = {
    sm: 'h-[26px] gap-1.5 rounded-lg px-2 text-[11.5px]',
    md: 'h-[30px] gap-2 rounded-lg px-[9px] text-[12.5px]',
    lg: 'h-[38px] gap-2 rounded-[9px] px-3 text-[13px]',
} as const;

/** Below this many options, filtering is more friction than help. */
const FILTER_FROM = 8;

const optionClass =
    'flex cursor-pointer items-center gap-[9px] rounded-[7px] px-[9px] py-[7px] text-fg-2 outline-none data-[disabled=true]:cursor-not-allowed data-[disabled=true]:opacity-45 data-[selected=true]:bg-panel-2 data-[selected=true]:text-fg';

function Caret({ open }: { open: boolean }) {
    return (
        <svg
            viewBox="0 0 10 10"
            fill="none"
            aria-hidden
            className={cn(
                'ml-auto size-2.5 shrink-0 transition-transform duration-150',
                open ? 'rotate-180 text-fg-2' : 'text-fg-3',
            )}
        >
            <path
                d="M2 3.9 5 6.9l3-3"
                stroke="currentColor"
                strokeWidth="1.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function Check() {
    return (
        <svg
            viewBox="0 0 12 12"
            fill="none"
            aria-hidden
            className="ml-auto size-[11px] shrink-0 text-primary"
        >
            <path
                d="M2.5 6.3 4.8 8.6 9.5 3.9"
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

/** "4/12" in the filter row. cmdk owns the count, so it is read from there. */
function MatchCount({ total }: { total: number }) {
    const shown = useCommandState((state) => state.filtered.count);

    return (
        <span className="shrink-0 font-mono text-[10px] text-fg-3">
            {shown}/{total}
        </span>
    );
}

/** Consecutive options sharing a `group` render under one heading. */
function sections(options: SelectOption[]) {
    const out: { heading?: string; items: SelectOption[] }[] = [];

    for (const option of options) {
        const last = out.at(-1);

        if (last && last.heading === option.group) {
            last.items.push(option);
        } else {
            out.push({ heading: option.group, items: [option] });
        }
    }

    return out;
}

export function Select({
    value,
    onChange,
    options,
    placeholder = 'Select…',
    size = 'md',
    mono = false,
    disabled = false,
    error = false,
    searchable,
    searchPlaceholder = 'Filter…',
    emptyLabel = 'Nothing matches.',
    /** Sits before the value inside the trigger - a "LEFT" / "RIGHT" caption. */
    prefix,
    label,
    className,
}: {
    value: string;
    onChange: (next: string) => void;
    options: SelectOption[];
    placeholder?: string;
    size?: keyof typeof SIZES;
    mono?: boolean;
    disabled?: boolean;
    error?: boolean;
    searchable?: boolean;
    searchPlaceholder?: string;
    emptyLabel?: string;
    prefix?: ReactNode;
    /** Accessible name when nothing visible labels the trigger. */
    label?: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);
    const filtering = searchable ?? options.length >= FILTER_FROM;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    disabled={disabled}
                    aria-label={label}
                    className={cn(
                        'flex w-full min-w-0 cursor-pointer items-center border bg-panel-2 text-left text-fg transition-colors outline-none',
                        SIZES[size],
                        open ? 'border-primary' : 'border-line-2',
                        error && 'border-crit',
                        disabled && 'cursor-not-allowed opacity-55',
                        className,
                    )}
                >
                    {prefix}
                    <span
                        className={cn(
                            'min-w-0 truncate',
                            mono && 'font-mono',
                            !selected && 'text-fg-3',
                        )}
                    >
                        {/*
                            A grouped option is only identified by its heading:
                            "prod" means nothing once the list is closed, so the
                            trigger carries the heading with it.
                        */}
                        {selected
                            ? [selected.group, selected.label]
                                  .filter(Boolean)
                                  .join(' / ')
                            : placeholder}
                    </span>
                    <Caret open={open} />
                </button>
            </PopoverTrigger>

            <PopoverContent
                align="start"
                sideOffset={5}
                // As wide as the trigger, but never so narrow that the options
                // are unreadable - the small triggers are 26px tall chips.
                style={{
                    width: 'max(var(--radix-popover-trigger-width), 200px)',
                }}
                className="overflow-hidden rounded-[10px] border-line-2 bg-panel p-0 shadow-[var(--shadow-panel)]"
            >
                <Command loop shouldFilter={filtering} label={label}>
                    <div
                        className={cn(
                            'flex items-center gap-2 border-b border-line px-[11px] py-2',
                            !filtering && 'sr-only',
                        )}
                    >
                        <span aria-hidden className="text-[11px] text-fg-3">
                            ⌕
                        </span>
                        <Command.Input
                            placeholder={searchPlaceholder}
                            className="min-w-0 flex-1 border-0 bg-transparent text-[12.5px] text-fg outline-none placeholder:text-fg-3"
                        />
                        {filtering && <MatchCount total={options.length} />}
                    </div>

                    <Command.List className="max-h-[172px] overflow-auto p-1">
                        {filtering && (
                            <Command.Empty className="px-4 py-4 text-center text-xs text-fg-3">
                                {emptyLabel}
                            </Command.Empty>
                        )}

                        {sections(options).map((section) => (
                            <Command.Group
                                key={section.heading ?? 'options'}
                                value={section.heading ?? 'options'}
                                heading={section.heading}
                                className="[&_[cmdk-group-heading]]:px-[9px] [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-[10.5px] [&_[cmdk-group-heading]]:tracking-[.06em] [&_[cmdk-group-heading]]:text-fg-3 [&_[cmdk-group-heading]]:uppercase"
                            >
                                {section.items.map((option) => (
                                    <Command.Item
                                        key={option.value}
                                        value={option.value}
                                        keywords={[option.label]}
                                        disabled={option.disabled}
                                        onSelect={() => {
                                            onChange(option.value);
                                            setOpen(false);
                                        }}
                                        className={cn(
                                            optionClass,
                                            option.value === value &&
                                                'bg-panel-2 text-fg',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'truncate',
                                                mono
                                                    ? 'font-mono text-xs'
                                                    : 'text-[12px]',
                                            )}
                                        >
                                            {option.label}
                                        </span>
                                        {option.meta && (
                                            <span className="ml-auto shrink-0 text-[10.5px] text-fg-3">
                                                {option.meta}
                                            </span>
                                        )}
                                        {option.value === value && <Check />}
                                    </Command.Item>
                                ))}
                            </Command.Group>
                        ))}
                    </Command.List>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/**
 * A labelled select, matching `Field` in `form.tsx` so the two stack cleanly in
 * the same form.
 */
export function SelectField({
    label,
    error,
    required,
    wrapperClassName,
    ...props
}: Omit<Parameters<typeof Select>[0], 'error' | 'label'> & {
    label: ReactNode;
    error?: string;
    /** Marks the label with the red asterisk - the choice must be made. */
    required?: boolean;
    wrapperClassName?: string;
}) {
    return (
        <div className={cn('mb-[13px]', wrapperClassName)}>
            <div className="mb-1.5 text-xs text-fg-2">
                {label}
                {required && <RequiredMark />}
            </div>
            <Select {...props} error={Boolean(error)} />
            <FieldError message={error} />
        </div>
    );
}
