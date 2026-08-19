import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { revealSchema } from '@/lib/validation/vault';
import { SensitivityDot } from '../data/sensitivity';
import type { Sensitivity } from '../data/sensitivity';
import { FieldError, fieldClass } from '../form/form';
import { PinInput } from '../form/pin-input';

/**
 * The reveal flow dialog. Collects the PIN (and account password for critical
 * variables) and hands them to `onSubmit`, which must call the server-side
 * reveal endpoint - the value itself never lives in this component.
 *
 * A shared file is read the same way and only ends differently: the bytes are
 * saved or previewed rather than printed, so `verb` renames the action and the
 * caller simply never passes a `value`.
 */
export function RevealDialog({
    open,
    onOpenChange,
    variableKey,
    path,
    level,
    requirement = 'pin',
    verb = 'Reveal',
    onSubmit,
    submitting = false,
    error,
    value,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    variableKey: string;
    /** Breadcrumb context, e.g. "acme / lebify / prod / payments" */
    path?: string;
    level: Sensitivity;
    /**
     * What this reveal costs, straight from the environment's matrix. `none`
     * means the caller has already asked the server and this dialog only has
     * to show the answer - asking for a PIN nobody will check is how a policy
     * that says "no PIN" ends up looking broken.
     */
    requirement?: 'none' | 'pin' | 'pin_password';
    /**
     * What the PIN is being spent on: "Reveal" by default, or "Open" and
     * "Download" for a shared file, where nothing is printed on screen.
     */
    verb?: string;
    onSubmit: (credentials: { pin: string; password?: string }) => void;
    submitting?: boolean;
    error?: string;
    /**
     * The revealed value, once the server has granted it. Passed in rather
     * than fetched here so the caller decides how long it lives - and it is
     * never written anywhere but this component's own render.
     */
    value?: string;
}) {
    const [pin, setPin] = useState('');
    const [password, setPassword] = useState('');
    const requirePassword = requirement === 'pin_password';
    // revealSchema is the one statement of what a reveal request looks like
    //; the password-presence rule is contextual, so it sits beside it.
    const canSubmit =
        revealSchema.safeParse({ pin, password }).success &&
        pin.length === 4 &&
        (!requirePassword || password.length > 0) &&
        !submitting;

    const reset = (next: boolean) => {
        if (!next) {
            setPin('');
            setPassword('');
        }

        onOpenChange(next);
    };

    return (
        <Dialog open={open} onOpenChange={reset}>
            <DialogContent className="w-[372px] border-line-2 bg-panel p-0">
                <DialogHeader className="flex-row items-center gap-2.5 space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <SensitivityDot level={level} size={8} />
                    <div>
                        <DialogTitle className="text-[13px] font-semibold">
                            {verb} {variableKey}
                        </DialogTitle>
                        {path && (
                            <DialogDescription className="font-mono text-[10.5px] text-fg-3">
                                {path}
                            </DialogDescription>
                        )}
                    </div>
                </DialogHeader>
                {value === undefined && requirement === 'none' ? (
                    <div className="px-[17px] pt-1 pb-[18px]">
                        <p className="text-[12.5px] text-fg-2">
                            {error
                                ? error
                                : `This environment asks for nothing to read a ${level} value. Fetching it…`}
                        </p>
                        <p className="mt-2.5 text-center text-[10.5px] text-fg-3">
                            Every reveal is logged.
                        </p>
                    </div>
                ) : value !== undefined ? (
                    <div className="px-[17px] pt-1 pb-[18px]">
                        <p className="mb-2.5 text-[12.5px] text-fg-2">
                            Shown once. Closing this clears it.
                        </p>
                        <div className="rounded-lg border border-line-2 bg-panel-2 p-2.5 font-mono text-[12.5px] break-all select-all">
                            {value}
                        </div>
                        <Button
                            type="button"
                            onClick={() => {
                                void navigator.clipboard?.writeText(value);
                            }}
                            className="mt-3 h-8 w-full text-[12.5px] font-semibold"
                        >
                            Copy
                        </Button>
                        <p className="mt-2.5 text-center text-[10.5px] text-fg-3">
                            This reveal has been logged.
                        </p>
                    </div>
                ) : (
                    <div className="px-[17px] pt-1 pb-[18px]">
                        <p className="mb-3.5 text-[12.5px] text-fg-2">
                            {requirePassword
                                ? 'Critical - enter your reveal PIN, then your account password.'
                                : 'Enter your 4-digit reveal PIN.'}
                        </p>
                        <PinInput value={pin} onChange={setPin} />
                        {requirePassword && (
                            <input
                                type="password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="Account password"
                                autoComplete="current-password"
                                className={cn(fieldClass, 'mt-3')}
                            />
                        )}
                        <FieldError message={error || undefined} />
                        <Button
                            type="button"
                            disabled={!canSubmit}
                            onClick={() =>
                                onSubmit({
                                    pin,
                                    password: requirePassword
                                        ? password
                                        : undefined,
                                })
                            }
                            className={cn(
                                'mt-4 h-8 w-full text-[12.5px] font-semibold',
                            )}
                        >
                            {submitting ? 'Checking…' : verb}
                        </Button>
                        <p className="mt-2.5 text-center text-[10.5px] text-fg-3">
                            Every reveal is logged.
                        </p>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
