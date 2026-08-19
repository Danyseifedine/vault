import { Link } from '@inertiajs/react';
import { ContextMenu } from '@/components/vault';

export interface DashboardOrganization {
    slug: string;
    name: string;
    canUpdate: boolean;
    canDelete: boolean;
    projects: number;
    members: number;
}

/**
 * One organization on the first screen. The right-click menu carries exactly
 * the actions this person's grants allow - the server refuses everyone else
 * regardless.
 */
export function OrganizationCard({
    organization,
    onRename,
    onDelete,
}: {
    organization: DashboardOrganization;
    onRename: () => void;
    onDelete: () => void;
}) {
    return (
        <ContextMenu
            items={[
                ...(organization.canUpdate
                    ? [{ label: 'Rename…', onSelect: onRename }]
                    : []),
                ...(organization.canDelete
                    ? [
                          {
                              label: 'Delete organization…',
                              onSelect: onDelete,
                              destructive: true,
                          },
                      ]
                    : []),
            ]}
        >
            <Link
                href={`/orgs/${organization.slug}`}
                className="block rounded-xl border border-line bg-panel p-[15px] text-inherit transition-colors hover:bg-panel-2"
            >
                <div className="mb-2.5 flex items-center gap-2">
                    <span className="grid size-[22px] shrink-0 place-items-center rounded-md bg-primary-soft text-[9.5px] font-semibold text-primary">
                        {organization.slug.slice(0, 2).toUpperCase()}
                    </span>
                    <span className="truncate text-[13px] font-semibold">
                        {organization.name}
                    </span>
                </div>
                <div className="flex gap-3.5 text-[11.5px] text-fg-3">
                    <span>
                        {organization.projects} project
                        {organization.projects === 1 ? '' : 's'}
                    </span>
                    <span>
                        {organization.members} member
                        {organization.members === 1 ? '' : 's'}
                    </span>
                </div>
            </Link>
        </ContextMenu>
    );
}
