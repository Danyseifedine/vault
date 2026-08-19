import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The frame every small form dialog shares: bordered header with a title and
 * one line of context, then whatever the caller renders. Grew up in the
 * personal vault; promoted once the tag dialogs needed the same shape.
 */

/**
 * The form layout inside the shell.
 *
 * min-w-0: the dialog is a grid, and a grid item's default minimum is its
 * content's width - wide preview contents would push the form outside the
 * fixed-width card instead of staying contained.
 */
export const formClass = 'flex min-w-0 flex-col gap-[15px] p-[17px] pt-1';

export function DialogShell({
    open,
    onOpenChange,
    title,
    description,
    children,
    wide,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={`max-h-[calc(100vh-2rem)] ${wide ? 'w-[800px]' : 'w-[420px]'} max-w-[calc(100vw-2rem)] overflow-y-auto border-line-2 bg-panel p-0`}
            >
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        {title}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        {description}
                    </DialogDescription>
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}
