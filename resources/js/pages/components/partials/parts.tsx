/** The showcase's own scaffolding: a titled section, a panel, a labelled slot. */

export function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="border-b border-line px-8 py-7">
            <div className="mb-4 font-mono text-[11px] tracking-wide text-primary uppercase">
                {title}
            </div>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] items-start gap-3.5">
                {children}
            </div>
        </section>
    );
}

export function Panel({
    label,
    children,
}: {
    label?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3.5 rounded-[11px] border border-line bg-panel p-4">
            {label && <div className="text-xs text-fg-3">{label}</div>}
            {children}
        </div>
    );
}

export function Field({
    label,
    hint,
    children,
}: {
    label: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <div className="mb-1.5 block text-xs text-fg-2">{label}</div>
            {children}
            {hint && (
                <div className="mt-[5px] text-[11.5px] text-fg-3">{hint}</div>
            )}
        </div>
    );
}
