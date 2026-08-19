/**
 * The pager under an audit table.
 *
 * Both audit screens - the organization's activity and a project's log - page
 * the same way over the same envelope, so the controls and the "1-25 of 400"
 * line live here once rather than twice.
 */

/** The paging half of what AuditPage::of returns. */
export interface AuditPageMeta {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

function PagerButton({
    disabled,
    onClick,
    children,
}: {
    disabled: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className="h-[26px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2.5 text-[11.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg disabled:cursor-not-allowed disabled:opacity-40"
        >
            {children}
        </button>
    );
}

export function AuditPager({
    meta,
    onPage,
    className,
}: {
    meta: AuditPageMeta;
    onPage: (page: number) => void;
    className?: string;
}) {
    const { page, perPage, total, lastPage } = meta;

    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);

    return (
        <div className={className}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-[11.5px] text-fg-3">
                    {from}-{to} of {total}
                </span>
                <div className="ml-auto flex items-center gap-1.5">
                    <PagerButton
                        disabled={page <= 1}
                        onClick={() => onPage(page - 1)}
                    >
                        Previous
                    </PagerButton>
                    <span className="px-1 text-[11.5px] text-fg-3">
                        Page {page} of {lastPage}
                    </span>
                    <PagerButton
                        disabled={page >= lastPage}
                        onClick={() => onPage(page + 1)}
                    >
                        Next
                    </PagerButton>
                </div>
            </div>
        </div>
    );
}
