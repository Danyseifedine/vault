import { fieldClass, Select } from '@/components/vault';
import { cn } from '@/lib/utils';
import type { AdminNamed } from '../types';

const UNGROUPED = 'ungrouped';
const NEW = 'new';

/**
 * Which group a variable is filed under, chosen where the variable itself is -
 * groups are a table of contents for the keys, so this is the only place they
 * are worth thinking about.
 *
 * `name` is set instead of `id` when a brand new group is being typed; the
 * server creates it through the same CreateGroup action as anywhere else, which
 * is what keeps the permission check and the audit entry in one place.
 */
export function GroupPicker({
    groups,
    id,
    name,
    onId,
    onName,
    canCreate,
}: {
    groups: AdminNamed[];
    id: number | null;
    /** Null when picking an existing group; a string while typing a new one. */
    name: string | null;
    onId: (id: number | null) => void;
    onName: (name: string | null) => void;
    canCreate: boolean;
}) {
    const value = name !== null ? NEW : id === null ? UNGROUPED : String(id);

    const choose = (next: string) => {
        if (next === NEW) {
            onId(null);
            onName('');

            return;
        }

        onName(null);
        onId(next === UNGROUPED ? null : Number(next));
    };

    return (
        <div>
            <div className="mb-1.5 block text-xs text-fg-2">Group</div>
            <Select
                label="Group"
                value={value}
                onChange={choose}
                options={[
                    { value: UNGROUPED, label: 'Ungrouped' },
                    ...groups.map((group) => ({
                        value: String(group.id),
                        label: group.name,
                        meta: `${group.variables} vars`,
                    })),
                    ...(canCreate ? [{ value: NEW, label: 'New group…' }] : []),
                ]}
            />
            {name !== null && (
                <input
                    autoFocus
                    maxLength={60}
                    value={name}
                    onChange={(event) => onName(event.target.value)}
                    placeholder="Database"
                    className={cn(fieldClass, 'mt-1.5')}
                />
            )}
        </div>
    );
}
