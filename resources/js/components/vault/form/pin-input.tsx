import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { cn } from '@/lib/utils';

/** The 4-digit reveal PIN input, built on input-otp. */
export function PinInput({
    value,
    onChange,
    onComplete,
    disabled,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    onComplete?: (value: string) => void;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <InputOTP
            maxLength={4}
            value={value}
            onChange={onChange}
            onComplete={onComplete}
            disabled={disabled}
            containerClassName={cn('w-full gap-2', className)}
            pattern="^[0-9]+$"
        >
            {/* Full width: the four slots share the row evenly, each flexing to
                fill its quarter rather than sitting at a fixed 46px on the left. */}
            <InputOTPGroup className="w-full gap-2">
                {[0, 1, 2, 3].map((i) => (
                    <InputOTPSlot
                        key={i}
                        index={i}
                        className="h-11 min-w-0 flex-1 rounded-lg border border-line-2 bg-panel-2 text-lg first:rounded-l-lg last:rounded-r-lg data-[active=true]:border-primary data-[active=true]:ring-0"
                    />
                ))}
            </InputOTPGroup>
        </InputOTP>
    );
}
