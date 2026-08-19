import { useState } from 'react';
import {
    DestructiveDialog,
    EmptyState,
    GuardedButton,
    LockedAction,
    RenameDialog,
} from '@/components/vault';
import { cn } from '@/lib/utils';
import { tagNameSchema } from '@/lib/validation/vault';
import type { OrgScope, OrgTag, OrganizationSummary } from '../types';
import { TagCreateDialog } from './tag-dialog';

const SCOPE_HINT: Record<string, string> = {
    organization: 'reaches every variable in the organization',
    project: 'reaches one project',
    environment: 'only sticks where the variable has a value',
};

/**
 * The organization's labelling vocabulary.
 *
 * A tag's SCOPE is chosen once and never edited - widening one later would
 * retroactively legitimise attachments that were refused at the time. Only the
 * owner may define an organization-wide tag.
 */
export function TagsScreen({
    organization,
    scopes,
    tags,
}: {
    organization: OrganizationSummary;
    scopes: OrgScope[];
    tags: OrgTag[];
}) {
    const [creating, setCreating] = useState(false);
    const [renaming, setRenaming] = useState<OrgTag | null>(null);
    const [removing, setRemoving] = useState<OrgTag | null>(null);

    return (
        <div className="w-full px-4 pt-[18px] pb-12 sm:px-7 sm:pt-[22px]">
            <div className="mb-[18px] flex flex-wrap items-end gap-3">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Tags
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        Labels with a reach. Scope is fixed when the tag is
                        created - that is what keeps “prod-only” meaningful.
                    </p>
                </div>
                <GuardedButton
                    allowed={organization.canCreateTags}
                    reason="Creating tags takes tags.create in a project, or tags.create-global"
                    onClick={() => setCreating(true)}
                    className="ml-auto"
                >
                    New tag
                </GuardedButton>
            </div>

            {tags.length === 0 ? (
                <EmptyState
                    art="tag"
                    title="No tags yet"
                    description="Create one to start labelling variables by concern."
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    {tags.map((tag) => (
                        <div
                            key={tag.id}
                            className="flex items-center gap-2.5 border-b border-line px-[15px] py-2.5"
                        >
                            <span className="text-[12.5px]">{tag.name}</span>
                            <span
                                className={cn(
                                    'rounded-full px-2 py-0.5 text-[10.5px]',
                                    tag.scope === 'organization'
                                        ? 'bg-primary-soft text-primary'
                                        : 'bg-panel-3 text-fg-3',
                                )}
                            >
                                {tag.scope}
                            </span>
                            <span className="text-[11px] text-fg-3">
                                {SCOPE_HINT[tag.scope]}
                            </span>

                            <span className="ml-auto flex gap-1.5">
                                {tag.canManage ? (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setRenaming(tag)}
                                            className="h-[23px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                                        >
                                            Rename
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setRemoving(tag)}
                                            className="h-[23px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2 transition-colors hover:border-crit-soft hover:bg-crit-soft hover:text-crit"
                                        >
                                            Delete
                                        </button>
                                    </>
                                ) : (
                                    <LockedAction
                                        className="h-[23px] w-[24px]"
                                        reason={
                                            tag.scope === 'organization'
                                                ? 'Organization-wide tags take tags.create-global'
                                                : 'This tag takes tags.create in its project'
                                        }
                                    />
                                )}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            <TagCreateDialog
                open={creating}
                onOpenChange={setCreating}
                organization={organization}
                scopes={scopes}
            />

            {renaming && (
                <RenameDialog
                    open
                    onOpenChange={(next) => !next && setRenaming(null)}
                    title={`Rename ${renaming.name}`}
                    description="Only the label changes - the scope stays exactly where it was set."
                    label="Tag name"
                    current={renaming.name}
                    schema={tagNameSchema}
                    action={`/orgs/${organization.slug}/tags/${renaming.id}`}
                />
            )}

            {removing && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setRemoving(null)}
                    title={`Delete the tag ${removing.name}?`}
                    description="A tag is only a label - the variables wearing it are untouched."
                    consequences={[
                        'Everything currently wearing it loses the label.',
                        'Its scope cannot be recreated retroactively for old attachments.',
                    ]}
                    action={`/orgs/${organization.slug}/tags/${removing.id}`}
                    buttonLabel="Delete tag"
                    successMessage="Tag deleted"
                    onDeleted={() => setRemoving(null)}
                />
            )}
        </div>
    );
}
