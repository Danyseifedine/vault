import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useFormSubmit } from '@/hooks/use-form-submit';
import { organizationSchema } from '@/lib/validation/vault';
import type { OrganizationValues } from '@/lib/validation/vault';
import { Field, FormAlert } from '../form/form';
import { DialogActions } from './dialog-actions';

/**
 * Changes the name of something that already exists.
 *
 * react-hook-form + zod like every other form: the same 1-120 rule
 * the server enforces, checked as you type, submit locked until it passes.
 */
export function RenameDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    current,
    action,
    schema = organizationSchema,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    label: string;
    current: string;
    /** Where to PATCH. The server re-validates and re-authorizes. */
    action: string;
    /**
     * The name rules to enforce - defaults to the 1-120 most names share, but
     * a tag caps at 40 and a looser client rule than the server's is worse
     * than none.
     */
    schema?: typeof organizationSchema;
}) {
    const form = useForm<OrganizationValues>({
        resolver: zodResolver(schema),
        mode: 'onChange',
        defaultValues: { name: current },
    });

    const close = () => {
        onOpenChange(false);
        form.reset({ name: current });
        resetErrors();
    };

    const { busy, serverError, errorFor, submit, resetErrors } = useFormSubmit(
        form,
        action,
        {
            method: 'patch',
            preserveScroll: true,
            fallback: 'That could not be renamed.',
            onSuccess: () => {
                toast.success('Renamed.');
                onOpenChange(false);
            },
        },
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
        >
            <DialogContent className="w-[400px] max-w-[calc(100vw-2rem)] border-line-2 bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        {title}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        {description}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="p-[17px] pt-1">
                    <Field
                        id="rename-name"
                        label={label}
                        required
                        autoFocus
                        error={errorFor('name')}
                        {...form.register('name')}
                    />

                    {serverError && <FormAlert>{serverError}</FormAlert>}

                    <DialogActions
                        onCancel={close}
                        busy={busy}
                        disabled={!form.formState.isValid}
                        label="Rename"
                        busyLabel="Saving…"
                        size="lg"
                        className="mt-0"
                    />
                </form>
            </DialogContent>
        </Dialog>
    );
}
