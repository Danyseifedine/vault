import { useState } from 'react';
import { toast } from 'sonner';
import {
    CreateDialog,
    DestructiveDialog,
    EmptyState,
    GuardedButton,
    RevealDialog,
} from '@/components/vault';
import { useSharedReads } from '@/hooks/use-shared-reads';
import type { ReadIntent } from '@/hooks/use-shared-reads';
import { sharedGroupSchema } from '@/lib/validation/shared';
import {
    ALL_GROUPS as ALL,
    filterSections,
    toSections,
} from '@/lib/vault-sections';
import type { OrganizationSummary, SharedItem, SharedVault } from '../types';
import { EditSharedItemDialog, NewSharedSecretDialog } from './shared-dialogs';
import { UploadSharedFileDialog } from './shared-file-dialog';
import { SharedList } from './shared-list';

/**
 * The credentials the organization holds in common - a server password, an SSH
 * key, a certificate - as opposed to a project's `.env` variables or a person's
 * own vault.
 *
 * Three separate grants govern it and the screen shows all three honestly:
 * without `shared.view` there is nothing here at all, and without
 * `shared.reveal` every read is a padlock rather than a refusal.
 *
 * Reading costs a PIN whatever the item is, so a file opens the same dialog a
 * secret does - `useSharedReads` owns that flow; this screen only draws it.
 */

const VERBS: Record<ReadIntent, string> = {
    reveal: 'Reveal',
    preview: 'Open',
    download: 'Download',
};

export function SharedScreen({
    organization,
    shared,
    query,
}: {
    organization: OrganizationSummary;
    shared: SharedVault;
    query: string;
}) {
    const [newSecret, setNewSecret] = useState(false);
    const [newFile, setNewFile] = useState(false);
    const [newGroup, setNewGroup] = useState(false);
    const [editing, setEditing] = useState<SharedItem | null>(null);

    const [removingItem, setRemovingItem] = useState<SharedItem | null>(null);
    const [removingGroup, setRemovingGroup] = useState<{
        id: number;
        name: string;
    } | null>(null);

    const base = `/orgs/${organization.slug}`;
    const reads = useSharedReads(base);

    const needle = query.trim().toLowerCase();
    const sections = filterSections(
        toSections(shared.groups, shared.items),
        needle,
        ALL,
    );

    const spendPin = (pin: string) => {
        void reads.submit(pin).then((message) => {
            if (message !== null) {
                toast.success(message);
            }
        });
    };

    return (
        <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <div className="mb-4 flex flex-wrap items-end gap-3.5">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Shared vault
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        Passwords, keys and files the team shares. Encrypted at
                        rest; reading one always asks for your PIN.
                    </p>
                </div>
                <div className="ml-auto flex items-center gap-2">
                    <GuardedButton
                        allowed={shared.canManage}
                        reason="Adding a group takes the shared.manage permission"
                        onClick={() => setNewGroup(true)}
                        tone="outline"
                    >
                        New group
                    </GuardedButton>
                    <GuardedButton
                        allowed={shared.canManage}
                        reason="Adding items takes the shared.manage permission"
                        onClick={() => setNewFile(true)}
                        tone="outline"
                    >
                        Upload file
                    </GuardedButton>
                    <GuardedButton
                        allowed={shared.canManage}
                        reason="Adding items takes the shared.manage permission"
                        onClick={() => setNewSecret(true)}
                    >
                        Add secret
                    </GuardedButton>
                </div>
            </div>

            {!shared.canView ? (
                <EmptyState
                    art="vault"
                    title="This vault is closed to you"
                    description="Seeing what the team shares takes the shared.view permission."
                />
            ) : sections.length === 0 ? (
                <EmptyState
                    art={shared.items.length === 0 ? 'vault' : 'search'}
                    title={
                        shared.items.length === 0
                            ? 'Nothing shared yet'
                            : 'Nothing matches your search'
                    }
                    description={
                        shared.items.length === 0
                            ? 'Add the passwords, keys and certificates that belong to the team rather than to one project.'
                            : 'No shared item goes by that name.'
                    }
                />
            ) : (
                <SharedList
                    sections={sections}
                    shown={reads.shown}
                    preview={reads.preview}
                    canReveal={shared.canReveal}
                    canManage={shared.canManage}
                    busy={reads.busy}
                    forceOpen={needle !== ''}
                    onReveal={(item) => reads.toggle(item, 'reveal')}
                    onTogglePreview={(item) => reads.toggle(item, 'preview')}
                    onDownload={(item) => reads.ask(item, 'download')}
                    onEdit={setEditing}
                    onRemove={setRemovingItem}
                    onRemoveGroup={setRemovingGroup}
                />
            )}

            <NewSharedSecretDialog
                open={newSecret}
                onOpenChange={setNewSecret}
                groups={shared.groups}
                action={`${base}/shared`}
            />
            <UploadSharedFileDialog
                open={newFile}
                onOpenChange={setNewFile}
                groups={shared.groups}
                action={`${base}/shared`}
            />
            <CreateDialog
                open={newGroup}
                onOpenChange={setNewGroup}
                title="New shared group"
                description="A folder in the team's vault: “staging server”, “Apple”."
                label="Group name"
                placeholder="Staging server"
                schema={sharedGroupSchema}
                action={`${base}/shared-groups`}
            />

            {editing && (
                <EditSharedItemDialog
                    item={editing}
                    groups={shared.groups}
                    action={`${base}/shared/${editing.id}`}
                    onClose={() => setEditing(null)}
                />
            )}

            {reads.asking && (
                <RevealDialog
                    open
                    onOpenChange={(next) => !next && reads.close()}
                    variableKey={reads.asking.item.name}
                    path={`${organization.name} / shared vault`}
                    level="sensitive"
                    requirement="pin"
                    verb={VERBS[reads.asking.intent]}
                    onSubmit={(credentials) => spendPin(credentials.pin)}
                    submitting={reads.busy}
                    error={reads.error}
                />
            )}

            {removingItem && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setRemovingItem(null)}
                    title={`Delete ${removingItem.name}?`}
                    description="A team credential removed by mistake is somebody locked out of a server."
                    consequences={[
                        'It disappears from the shared vault for everyone.',
                        'The encrypted copy is kept, so this can be undone by an administrator.',
                    ]}
                    action={`${base}/shared/${removingItem.id}`}
                    buttonLabel="Delete it"
                    onDeleted={() => {
                        reads.forget(removingItem.id);
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
                    action={`${base}/shared-groups/${removingGroup.id}`}
                    buttonLabel="Delete group"
                    onDeleted={() => setRemovingGroup(null)}
                />
            )}
        </div>
    );
}
