import { HeaderSearch, Select } from '@/components/vault';
import { ALL_GROUPS, sectionKey } from '@/lib/vault-sections';
import type { SectionItem, VaultSection } from '@/lib/vault-sections';

/** The search box, the group filter, and the way to clear both. */
export function VaultToolbar({
    query,
    onQuery,
    group,
    onGroup,
    sections,
    total,
    filtered,
    onClear,
}: {
    query: string;
    onQuery: (next: string) => void;
    group: string;
    onGroup: (next: string) => void;
    /** Every section, unfiltered - the counts must not shrink as you type. */
    sections: VaultSection<SectionItem>[];
    total: number;
    filtered: boolean;
    onClear: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <HeaderSearch
                value={query}
                onChange={onQuery}
                placeholder="Search groups and keys…"
                className="w-full sm:w-[260px]"
            />
            <Select
                label="Filter by group"
                className="w-[190px]"
                value={group}
                onChange={onGroup}
                searchPlaceholder="Filter groups…"
                emptyLabel="No group matches."
                options={[
                    {
                        value: ALL_GROUPS,
                        label: 'All groups',
                        meta: `${total}`,
                    },
                    ...sections.map((s) => ({
                        value: sectionKey(s.id),
                        label: s.name,
                        meta: `${s.rows.length}`,
                    })),
                ]}
            />
            {filtered && (
                <button
                    type="button"
                    onClick={onClear}
                    className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                >
                    Clear
                </button>
            )}
        </div>
    );
}
