import { cn } from '@/lib/utils';

export type AuditKind = 'ok' | 'warn' | 'fail';

export interface AuditRow {
    time: string;
    actor: string;
    action: string;
    kind: AuditKind;
    path: string;
    hash: string;
}

export const KIND_TONES: Record<
    AuditKind,
    { text: string; bg: string; dot: string }
> = {
    ok: { text: 'text-pub', bg: 'bg-pub-soft', dot: 'bg-pub' },
    warn: { text: 'text-sens', bg: 'bg-sens-soft', dot: 'bg-sens' },
    fail: { text: 'text-crit', bg: 'bg-crit-soft', dot: 'bg-crit' },
};

export function KindPill({
    kind,
    label,
    className,
}: {
    kind: AuditKind;
    label: string;
    className?: string;
}) {
    const t = KIND_TONES[kind];

    return (
        <span
            className={cn(
                'inline-flex w-fit max-w-full items-center truncate rounded-full px-2 py-0.5 text-[10.5px]',
                t.text,
                t.bg,
                className,
            )}
        >
            {label}
        </span>
    );
}

/** Hash-chained audit log table: time / who / action / path / chain. */
export function AuditTable({
    rows,
    className,
}: {
    rows: AuditRow[];
    className?: string;
}) {
    const grid =
        'grid grid-cols-[70px_minmax(110px,150px)_minmax(120px,1fr)_minmax(160px,1.6fr)_minmax(96px,118px)] gap-3';

    return (
        // Five columns do not fit a phone, and squeezing them would turn every
        // path and hash into an ellipsis. The table scrolls inside its own box
        // instead, so the page itself never scrolls sideways.
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-line bg-panel',
                className,
            )}
        >
            <div className="overflow-x-auto">
                <div className="min-w-[680px]">
                    <div
                        className={cn(
                            grid,
                            'border-b border-line bg-panel-2 px-[15px] py-[9px] text-[10.5px] font-semibold tracking-[.06em] text-fg-3 uppercase',
                        )}
                    >
                        <div>Time</div>
                        <div>Who</div>
                        <div>Action</div>
                        <div>Path</div>
                        <div>Chain</div>
                    </div>
                    {rows.map((l, i) => (
                        <div
                            key={`${l.time}-${i}`}
                            className={cn(
                                grid,
                                'min-h-[42px] items-center border-b border-line px-[15px] transition-colors hover:bg-panel-2',
                            )}
                        >
                            <div className="font-mono text-[11px] text-fg-3">
                                {l.time}
                            </div>
                            <div className="min-w-0">
                                <div className="truncate text-xs">
                                    {l.actor}
                                </div>
                            </div>
                            <div className="min-w-0">
                                <KindPill kind={l.kind} label={l.action} />
                            </div>
                            <div className="truncate font-mono text-[11px] text-fg-2">
                                {l.path}
                            </div>
                            <div className="flex items-center gap-1.5 font-mono text-[10.5px] text-fg-3">
                                <span className="size-[5px] rounded-full bg-pub" />
                                {l.hash}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
