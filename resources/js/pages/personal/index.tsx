import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    ActionTrail,
    CreateDialog,
    DestructiveDialog,
    EmptyState,
} from '@/components/vault';
import { useReveal } from '@/hooks/use-reveal';
import AccountLayout from '@/layouts/account-layout';
import { personalGroupSchema } from '@/lib/validation/personal';
import {
    ALL_GROUPS as ALL,
    filterSections,
    toSections,
} from '@/lib/vault-sections';
import { UploadFileDialog } from './partials/file-dialog';
import { EditItemDialog, NewSecretDialog } from './partials/item-dialogs';
import { VaultList } from './partials/vault-list';
import { VaultToolbar } from './partials/vault-toolbar';
import type { PersonalItem, PersonalPageProps } from './types';

export default function PersonalIndex({
    groups,
    items,
    activity,
}: PersonalPageProps) {
    const [newSecret, setNewSecret] = useState(false);
    const [newFile, setNewFile] = useState(false);
    const [newGroup, setNewGroup] = useState(false);
    const [editing, setEditing] = useState<PersonalItem | null>(null);

    const [query, setQuery] = useState('');
    const [group, setGroup] = useState(ALL);

    // The revealed value lives here only, and only while its row is open.
    const [shown, setShown] = useState<{ id: number; value: string } | null>(
        null,
    );

    // Which file is showing its contents. One at a time, and the panel is
    // unmounted when it closes, so the plaintext goes with it.
    const [previewing, setPreviewing] = useState<number | null>(null);
    const { reveal, reset: resetReveal, busy } = useReveal();

    const showValue = async (item: PersonalItem) => {
        if (shown?.id === item.id) {
            setShown(null);
            // The hook keeps the last plaintext internally - drop that too.
            resetReveal();

            return;
        }

        const outcome = await reveal(`/personal/items/${item.id}/reveal`, {});

        if (outcome.granted) {
            setShown({ id: item.id, value: outcome.value });
        } else {
            toast.error(outcome.message);
        }
    };

    /*
     * Deleting asks in a dialog rather than a browser confirm(): the native one
     * cannot say what is about to be lost, looks like a different application,
     * and is dismissed by reflex. Both of these are irreversible.
     */
    /*
     * Opening the editor hides any value currently revealed for that row. The
     * editor can now replace it, and a revealed value is component state: it
     * would happily go on showing the old secret next to the new one.
     */
    const edit = (item: PersonalItem) => {
        setShown((current) => (current?.id === item.id ? null : current));
        resetReveal();
        setEditing(item);
    };

    const [removingItem, setRemovingItem] = useState<PersonalItem | null>(null);
    const [removingGroup, setRemovingGroup] = useState<{
        id: number;
        name: string;
    } | null>(null);

    const needle = query.trim().toLowerCase();

    const all = toSections(groups, items);
    const sections = filterSections(all, needle, group);

    const empty = items.length === 0 && groups.length === 0;
    const filtered = needle !== '' || group !== ALL;

    return (
        <div className="flex min-h-full w-full flex-col px-4 pt-[20px] pb-12 sm:px-7 sm:pt-[26px]">
            <Head title="Personal vault" />
            <div className="flex flex-1 flex-col gap-4">
                <div className="flex items-end gap-3">
                    <div>
                        <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                            Your personal vault
                        </h1>
                        <p className="text-[13px] text-fg-2">
                            Private to you. No owner, admin or anyone else can
                            read these - by design, not by permission.
                        </p>
                    </div>
                    <div className="ml-auto flex gap-2">
                        <button
                            type="button"
                            onClick={() => setNewGroup(true)}
                            className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] whitespace-nowrap transition-colors hover:bg-panel-2"
                        >
                            New group
                        </button>
                        <button
                            type="button"
                            onClick={() => setNewFile(true)}
                            className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] whitespace-nowrap transition-colors hover:bg-panel-2"
                        >
                            Upload file
                        </button>
                        <button
                            type="button"
                            onClick={() => setNewSecret(true)}
                            className="h-[30px] cursor-pointer rounded-lg border-0 bg-primary px-3 text-[12.5px] font-semibold whitespace-nowrap text-primary-foreground transition-[filter] hover:brightness-108"
                        >
                            New secret
                        </button>
                    </div>
                </div>

                {!empty && (
                    <VaultToolbar
                        query={query}
                        onQuery={setQuery}
                        group={group}
                        onGroup={setGroup}
                        sections={all}
                        total={items.length}
                        filtered={filtered}
                        onClear={() => {
                            setQuery('');
                            setGroup(ALL);
                        }}
                    />
                )}

                {empty ? (
                    <EmptyState
                        art="vault"
                        title="Nothing here yet"
                        description="Your own credentials and files, encrypted at rest, readable by nobody else. Not by an owner, not by an admin, not by anyone."
                        action={
                            <button
                                type="button"
                                onClick={() => setNewSecret(true)}
                                className="h-[34px] cursor-pointer rounded-[9px] border-0 bg-primary px-4 text-[12.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108"
                            >
                                Store your first secret
                            </button>
                        }
                    />
                ) : sections.length === 0 ? (
                    <EmptyState
                        art="search"
                        title="Nothing matches"
                        description="No group or key here goes by that name."
                        action={
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    setGroup(ALL);
                                }}
                                className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] transition-colors hover:bg-panel-2"
                            >
                                Clear the search
                            </button>
                        }
                    />
                ) : (
                    <VaultList
                        sections={sections}
                        shown={shown}
                        previewing={previewing}
                        revealBusy={busy}
                        forceOpen={needle !== ''}
                        onReveal={showValue}
                        onTogglePreview={(item) =>
                            setPreviewing((current) =>
                                current === item.id ? null : item.id,
                            )
                        }
                        onEdit={edit}
                        onRemove={setRemovingItem}
                        onRemoveGroup={setRemovingGroup}
                    />
                )}

                {activity.length > 0 && (
                    <div className="overflow-hidden rounded-xl border border-line bg-panel">
                        <div className="flex items-center gap-2 border-b border-line px-[15px] py-3">
                            <div className="text-[13px] font-semibold">
                                Your private trail
                            </div>
                            <div className="text-[11.5px] text-fg-3">
                                visible to you alone - these never reach an
                                organization feed
                            </div>
                        </div>
                        <ActionTrail
                            entries={activity}
                            emptyLabel="Nothing yet."
                        />
                    </div>
                )}
            </div>

            <NewSecretDialog
                open={newSecret}
                onOpenChange={setNewSecret}
                groups={groups}
            />
            <UploadFileDialog
                open={newFile}
                onOpenChange={setNewFile}
                groups={groups}
            />
            <CreateDialog
                open={newGroup}
                onOpenChange={setNewGroup}
                title="New group"
                description="A folder inside your vault. Names only need to be unique to you."
                label="Group name"
                placeholder="Work"
                schema={personalGroupSchema}
                action="/personal/groups"
            />
            {editing && (
                <EditItemDialog
                    item={editing}
                    groups={groups}
                    onClose={() => setEditing(null)}
                />
            )}

            {removingItem && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setRemovingItem(null)}
                    title={`Delete ${removingItem.name}?`}
                    description="Only you can read this, which also means only you can lose it."
                    consequences={[
                        removingItem.type === 'file'
                            ? 'The encrypted file is removed from disk.'
                            : 'The stored value is destroyed.',
                        'There is no copy anywhere, and no administrative override to recover it.',
                    ]}
                    action={`/personal/items/${removingItem.id}`}
                    buttonLabel="Delete it"
                    onDeleted={() => {
                        setShown((current) =>
                            current?.id === removingItem.id ? null : current,
                        );
                        setPreviewing((current) =>
                            current === removingItem.id ? null : current,
                        );
                        setRemovingItem(null);
                    }}
                />
            )}

            {removingGroup && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setRemovingGroup(null)}
                    title={`Delete the group ${removingGroup.name}?`}
                    description="The group only organises things; it does not hold them."
                    consequences={[
                        'Everything inside it is kept and becomes ungrouped.',
                    ]}
                    action={`/personal/groups/${removingGroup.id}`}
                    buttonLabel="Delete group"
                    onDeleted={() => {
                        setGroup((current) =>
                            current === String(removingGroup.id)
                                ? ALL
                                : current,
                        );
                        setRemovingGroup(null);
                    }}
                />
            )}
        </div>
    );
}

PersonalIndex.layout = (page: React.ReactNode) => (
    <AccountLayout active="personal">{page}</AccountLayout>
);
