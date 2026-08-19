/**
 * Grouping and filtering a vault's contents.
 *
 * The personal vault and the organization's shared vault hold different things
 * under different rules, but they are filed and searched identically: groups
 * become sections, Ungrouped comes last, and a search flattens the folding.
 * One implementation, so the two can never drift.
 */

export const ALL_GROUPS = 'all';

export const sectionKey = (id: number | null) =>
    id === null ? 'ungrouped' : String(id);

/** The least an item must carry to be filed and searched. */
export interface SectionItem {
    groupId: number | null;
    name: string;
    description: string | null;
}

/** The least a group must carry to become a section. */
export interface SectionGroup {
    id: number;
    name: string;
}

export interface VaultSection<T extends SectionItem> {
    /** `null` is the Ungrouped bucket, which is not a real group. */
    id: number | null;
    name: string;
    rows: T[];
}

/** Every group as a section, Ungrouped last, rows filed where they belong. */
export function toSections<T extends SectionItem>(
    groups: SectionGroup[],
    items: T[],
): VaultSection<T>[] {
    return [
        ...groups.map((group) => ({
            id: group.id,
            name: group.name,
            rows: items.filter((item) => item.groupId === group.id),
        })),
        {
            id: null,
            name: 'Ungrouped',
            rows: items.filter((item) => item.groupId === null),
        },
    ];
}

/**
 * The search and the group filter, applied together.
 *
 * A group whose own name matches keeps everything inside it: you searched for
 * the group, not for something in it. Otherwise the needle runs over the items,
 * which is names and notes only - a value is never in the page props to search
 *.
 */
export function filterSections<T extends SectionItem>(
    sections: VaultSection<T>[],
    needle: string,
    group: string,
): VaultSection<T>[] {
    return sections
        .filter((s) => group === ALL_GROUPS || group === sectionKey(s.id))
        .map((s) => {
            const named =
                needle !== '' && s.name.toLowerCase().includes(needle);

            return {
                ...s,
                rows:
                    needle === '' || named
                        ? s.rows
                        : s.rows.filter(
                              (item) =>
                                  item.name.toLowerCase().includes(needle) ||
                                  (item.description ?? '')
                                      .toLowerCase()
                                      .includes(needle),
                          ),
            };
        })
        .filter((s) =>
            needle === ''
                ? // An empty named group still shows, so you can file into it.
                  s.rows.length > 0 || s.id !== null
                : s.rows.length > 0 || s.name.toLowerCase().includes(needle),
        );
}
