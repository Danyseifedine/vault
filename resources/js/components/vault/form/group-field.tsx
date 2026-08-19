import { Controller } from 'react-hook-form';
import type { Control, FieldValues, Path } from 'react-hook-form';
import { SelectField } from './select';

/**
 * "File this somewhere, or nowhere" - the picker every vault dialog shares.
 *
 * Personal items and shared items are filed the same way and only disagree on
 * which column holds the answer, so the field name is a prop rather than a
 * reason to have two of these.
 */

/** A group as any picker needs it: something to show, something to send. */
export interface PickableGroup {
    id: number;
    name: string;
}

/** The select's empty string means "ungrouped", which the server wants as null. */
export const groupId = (value: string): number | null =>
    value === '' ? null : Number(value);

export function GroupSelectField<T extends FieldValues>({
    name,
    groups,
    control,
}: {
    /** The column this form files into: `personal_group_id`, `shared_group_id`. */
    name: Path<T>;
    groups: PickableGroup[];
    control: Control<T>;
}) {
    return (
        <Controller
            control={control}
            name={name}
            render={({ field }) => (
                <SelectField
                    wrapperClassName="mb-0"
                    label={
                        <>
                            Group <span className="text-fg-3">(optional)</span>
                        </>
                    }
                    size="lg"
                    value={String(field.value ?? '')}
                    onChange={field.onChange}
                    options={[
                        { value: '', label: 'Ungrouped' },
                        ...groups.map((group) => ({
                            value: String(group.id),
                            label: group.name,
                        })),
                    ]}
                />
            )}
        />
    );
}
