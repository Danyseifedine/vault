import { toast } from 'sonner';

/**
 * Visit options every stay-on-the-page mutation shares: keep the scroll where
 * it was, toast the outcome. The error toast surfaces the server's first field
 * message, because "that was refused" with no reason is not feedback.
 *
 * Each screen used to define its own copy of this - same shape, drifting
 * wording. One place now.
 */
export const flash = (message: string) => ({
    preserveScroll: true,
    onSuccess: () => toast.success(message),
    onError: (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0] ?? 'That was refused.'),
});
