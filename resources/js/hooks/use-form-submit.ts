import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FieldValues, UseFormReturn } from 'react-hook-form';

type Options<T extends FieldValues> = {
    /** Merged into the payload - the reset flow's token and email, for example. */
    extra?: Record<string, unknown>;
    /** Shown when the server refuses without naming a field. */
    fallback?: string;
    onSuccess?: () => void;
    /** Defaults to POST. */
    method?: 'post' | 'put' | 'patch';
    /** Multipart, for a form carrying a File. */
    forceFormData?: boolean;
    preserveScroll?: boolean;
    /**
     * Last chance to reshape the values before they go: turning a select's
     * empty string into a null, dropping a field the endpoint does not take.
     * The keys must keep their names, or a server error cannot find its field.
     */
    transform?: (values: T) => Record<string, unknown>;
};

/**
 * The half of every form that is not the fields: submit only once the client
 * schema passes, hold the request's in-flight state, and fold the server's
 * answer back onto the right input.
 *
 * Client validation is UX and the server is truth, which is
 * why `errorFor` prefers the schema's message but still surfaces the server's:
 * a rule the browser cannot check, like "that email has no account", can only
 * come back from the request.
 */
export function useFormSubmit<T extends FieldValues>(
    form: UseFormReturn<T>,
    url: string,
    {
        extra,
        fallback = 'That was refused.',
        onSuccess,
        method = 'post',
        forceFormData,
        preserveScroll,
        transform,
    }: Options<T> = {},
) {
    const [busy, setBusy] = useState(false);
    const [serverError, setServerError] = useState('');
    const [serverFieldErrors, setServerFieldErrors] = useState<
        Record<string, string>
    >({});

    const submit = form.handleSubmit((values) => {
        setServerError('');
        setServerFieldErrors({});
        setBusy(true);

        const payload = {
            ...(transform ? transform(values) : values),
            ...extra,
        } as Parameters<typeof router.post>[1];

        router[method](url, payload, {
            forceFormData,
            preserveScroll,
            onError: (errors) => {
                const named = Object.keys(errors).filter(
                    (key) => key in form.getValues(),
                );

                if (named.length > 0) {
                    setServerFieldErrors(errors);
                } else {
                    setServerError(Object.values(errors)[0] ?? fallback);
                }
            },
            onSuccess: () => onSuccess?.(),
            onFinish: () => setBusy(false),
        });
    });

    const errorFor = (name: keyof T & string): string | undefined =>
        (form.formState.errors[name]?.message as string | undefined) ??
        serverFieldErrors[name];

    /** Clears both server messages, so reopening a dialog starts clean. */
    const resetErrors = () => {
        setServerError('');
        setServerFieldErrors({});
    };

    return { busy, serverError, errorFor, submit, resetErrors };
}
