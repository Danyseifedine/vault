import type { ComponentProps, ReactNode } from 'react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * The vocabulary every form in the product speaks - sign-in, the reset flow,
 * onboarding, and the dialogs inside the application alike.
 *
 * They were three different design languages stitched together: the starter
 * kit's shadcn forms, a hand-built sign-in, and dialogs that hand-rolled their
 * own inputs. This is the hand-built one, extracted, so there is exactly one
 * answer to "what does a field look like, and what does it look like when it is
 * wrong".
 */

export const fieldClass =
    'h-[38px] w-full rounded-[9px] border border-line-2 bg-panel-2 px-3 text-[13px] text-fg outline-none placeholder:text-fg-3 focus:border-primary';

/**
 * A field that has been refused: a red edge, and the message below it. No red
 * fill - the input keeps its normal background so the text you are being asked
 * to correct stays as readable as it was while you typed it.
 */
const errorClass = 'border-crit focus:border-crit';

/**
 * The red asterisk after a required field's label. aria-hidden because the
 * input itself carries `aria-required` - a screen reader hearing "star" twice
 * helps nobody.
 */
export function RequiredMark() {
    return (
        <span aria-hidden className="ml-0.5 font-bold text-crit">
            *
        </span>
    );
}

/** Inline field error, animated in below the input. */
export function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <div className="mt-1.5 animate-in text-[11px] font-semibold text-crit duration-200 fade-in slide-in-from-top-1">
            {message}
        </div>
    );
}

type LabelledProps = {
    label: string;
    error?: string;
    /** Sits opposite the label - a "Forgot password?" link, usually. */
    aside?: ReactNode;
    /**
     * Marks the label with the red asterisk. Kept away from the native input
     * attribute on purpose: the browser's own validation bubble would race the
     * zod schema that actually owns the rules.
     */
    required?: boolean;
    /**
     * For the wrapper, not the input. Forms that space their own children with
     * `gap` pass `mb-0` here so the field stops adding a second gap.
     */
    wrapperClassName?: string;
};

type FieldProps = ComponentProps<'input'> &
    LabelledProps & {
        /** Safari/iOS password-generator hint; mirrors Password::default(). */
        passwordrules?: string;
    };

function Label({
    label,
    aside,
    required,
    htmlFor,
}: {
    label: string;
    aside?: ReactNode;
    required?: boolean;
    htmlFor?: string;
}) {
    return (
        <div className="mb-1.5 flex items-center">
            <label htmlFor={htmlFor} className="text-xs text-fg-2">
                {label}
                {required && <RequiredMark />}
            </label>
            {aside && <div className="ml-auto">{aside}</div>}
        </div>
    );
}

export function Field({
    label,
    error,
    aside,
    required,
    className,
    wrapperClassName,
    id,
    ...props
}: FieldProps) {
    return (
        <div className={cn('mb-[13px]', wrapperClassName)}>
            <Label
                label={label}
                aside={aside}
                required={required}
                htmlFor={id}
            />
            <input
                id={id}
                aria-required={required || undefined}
                className={cn(fieldClass, error && errorClass, className)}
                {...props}
            />
            <FieldError message={error} />
        </div>
    );
}

/** The same field, for the values too long to sit on one line. */
export function TextareaField({
    label,
    error,
    aside,
    required,
    className,
    wrapperClassName,
    id,
    ...props
}: ComponentProps<'textarea'> & LabelledProps) {
    return (
        <div className={cn('mb-[13px]', wrapperClassName)}>
            <Label
                label={label}
                aside={aside}
                required={required}
                htmlFor={id}
            />
            <textarea
                id={id}
                aria-required={required || undefined}
                className={cn(
                    fieldClass,
                    'h-auto resize-y py-2.5',
                    error && errorClass,
                    className,
                )}
                {...props}
            />
            <FieldError message={error} />
        </div>
    );
}

/**
 * A password field with a reveal toggle. Typing a long password blind is how
 * people end up choosing short ones.
 */
export function PasswordField({
    label,
    error,
    aside,
    required,
    className,
    wrapperClassName,
    id,
    ...props
}: FieldProps) {
    const [shown, setShown] = useState(false);

    return (
        <div className={cn('mb-[13px]', wrapperClassName)}>
            <Label
                label={label}
                aside={aside}
                required={required}
                htmlFor={id}
            />
            <div
                className={cn(
                    'flex h-[38px] items-center gap-2 rounded-[9px] border border-line-2 bg-panel-2 py-0 pr-2 pl-3 focus-within:border-primary',
                    error && 'border-crit focus-within:border-crit',
                )}
            >
                <input
                    id={id}
                    aria-required={required || undefined}
                    type={shown ? 'text' : 'password'}
                    placeholder="••••••••"
                    className={cn(
                        'min-w-0 flex-1 border-0 bg-transparent text-[13px] text-fg outline-none placeholder:text-fg-3',
                        className,
                    )}
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setShown((s) => !s)}
                    className="h-[25px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[11px] text-fg-2 transition-colors hover:bg-panel-3 hover:text-fg"
                >
                    {shown ? 'Hide' : 'Show'}
                </button>
            </div>
            <FieldError message={error} />
        </div>
    );
}

export function SubmitButton({
    children,
    busy,
    busyLabel,
    className,
    ...props
}: ComponentProps<'button'> & { busy?: boolean; busyLabel?: string }) {
    return (
        <button
            type="submit"
            disabled={busy || props.disabled}
            className={cn(
                'mt-3.5 flex h-[38px] w-full cursor-pointer items-center justify-center gap-2 rounded-[9px] border-0 bg-primary text-[13px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108 disabled:opacity-85',
                // The progress cursor means "working" - only true while the
                // request is in flight, not while the form is merely invalid.
                busy
                    ? 'disabled:cursor-progress'
                    : 'disabled:cursor-not-allowed',
                className,
            )}
            {...props}
        >
            {busy && (
                <span className="inline-block size-3 animate-spin rounded-full border-2 border-current/25 border-t-current" />
            )}
            {busy && busyLabel ? busyLabel : children}
        </button>
    );
}

/** A whole-form message: a refusal in `crit`, a confirmation in `pub`. */
export function FormAlert({
    tone = 'crit',
    children,
}: {
    tone?: 'crit' | 'pub';
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'mt-3 animate-in rounded-[9px] px-3 py-2.5 text-xs font-semibold duration-200 fade-in slide-in-from-top-1',
                tone === 'crit'
                    ? 'border border-crit-soft bg-crit-soft text-crit'
                    : 'border border-pub-soft bg-pub-soft text-pub',
            )}
        >
            {children}
        </div>
    );
}

/** A quiet secondary action under a form - "return to sign in", "log out". */
export function FormNote({ children }: { children: ReactNode }) {
    return (
        <div className="mt-4 text-center text-[11.5px] text-pretty text-fg-3">
            {children}
        </div>
    );
}
