import { Head, Link, usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { useState } from 'react';
import {
    ActionTrail,
    AppShell,
    CreateDialog,
    DestructiveDialog,
    EmptyState,
    GuardedButton,
    HeaderSearch,
    RenameDialog,
    ShellHeader,
    SidebarBrand,
    SidebarFooter,
    SidebarHeading,
    SidebarMonoRow,
    SidebarNav,
    StatCard,
} from '@/components/vault';
import type { TrailEntry } from '@/components/vault';
import { useInitials } from '@/hooks/use-initials';
import { index as personalVault } from '@/routes/personal';
import { edit as editProfile } from '@/routes/profile';
import { OrganizationCard } from './partials/organization-card';
import type { DashboardOrganization } from './partials/organization-card';

type Props = {
    organizations: DashboardOrganization[];
    recentActivity: TrailEntry[];
    stats: {
        organizations: number;
        projects: number;
        environments: number;
        variables: number;
    };
    /** An account capability, handed out from the command line. */
    canCreateOrganizations: boolean;
};

export default function Dashboard({
    organizations,
    stats,
    recentActivity,
    canCreateOrganizations,
}: Props) {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const [creating, setCreating] = useState(false);
    const [renaming, setRenaming] = useState<DashboardOrganization | null>(
        null,
    );
    const [deleting, setDeleting] = useState<DashboardOrganization | null>(
        null,
    );
    const [query, setQuery] = useState('');

    const shown = organizations.filter(
        (o) => !query || o.name.toLowerCase().includes(query.toLowerCase()),
    );

    const sidebar = (
        <>
            <SidebarBrand context="home" />
            <SidebarNav
                items={[{ id: 'home', label: 'Overview', icon: LayoutGrid }]}
                active="home"
                onSelect={() => {}}
            />
            <SidebarHeading>Organizations</SidebarHeading>
            <div className="flex flex-col gap-px overflow-auto px-2.5">
                {organizations.length === 0 ? (
                    <div className="px-2 py-1.5 text-[11.5px] text-fg-3">
                        None yet
                    </div>
                ) : (
                    organizations.map((organization) => (
                        <SidebarMonoRow
                            key={organization.slug}
                            name={organization.name}
                            meta={String(organization.projects)}
                            href={`/orgs/${organization.slug}`}
                        />
                    ))
                )}
            </div>
            <SidebarFooter
                name={auth.user.name}
                meta={auth.user.email}
                initials={getInitials(auth.user.name)}
            />
        </>
    );

    return (
        <AppShell sidebar={sidebar}>
            <Head title="Dashboard" />

            <ShellHeader crumb="home" active="Overview">
                <div className="flex w-full min-w-0 items-center gap-2 sm:ml-auto sm:w-auto">
                    <HeaderSearch
                        value={query}
                        onChange={setQuery}
                        placeholder="Search organizations…"
                    />
                    <GuardedButton
                        allowed={canCreateOrganizations}
                        reason="Starting organizations is enabled per account, from the command line"
                        onClick={() => setCreating(true)}
                        className="shrink-0"
                    >
                        New organization
                    </GuardedButton>
                </div>
            </ShellHeader>

            <div className="flex-1 overflow-auto">
                <div className="flex min-h-full w-full flex-col px-4 pt-[20px] pb-12 sm:px-7 sm:pt-[26px]">
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        {auth.user.name?.split(' ')[0] ?? 'Welcome'}
                    </h1>
                    <p className="mb-[18px] text-[13px] text-fg-2">
                        Everything you have been added to. Nothing here is
                        discoverable - you see it because someone put you in it.
                    </p>

                    {/* Counts, never contents: how much there is to look after,
                        without naming a single key. */}
                    <div className="mb-[26px] grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3">
                        <StatCard
                            label="Organizations"
                            value={stats.organizations}
                        />
                        <StatCard label="Projects" value={stats.projects} />
                        <StatCard
                            label="Environments"
                            value={stats.environments}
                        />
                        <StatCard
                            label="Variables"
                            value={stats.variables}
                            meta="under management"
                        />
                    </div>

                    <div className="mb-3 flex flex-wrap items-end gap-3">
                        <div>
                            <h2 className="text-[15px] font-semibold tracking-tight">
                                Organizations
                            </h2>
                            <p className="text-[12.5px] text-fg-2">
                                Open one to reach its projects and variables.
                            </p>
                        </div>
                        <Link
                            href={personalVault()}
                            className="ml-auto h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] leading-[28px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                        >
                            Personal vault
                        </Link>
                        <Link
                            href={editProfile()}
                            className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-[12.5px] leading-[28px] text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                        >
                            Settings
                        </Link>
                    </div>

                    {shown.length === 0 ? (
                        <EmptyState
                            art={organizations.length === 0 ? 'org' : 'search'}
                            action={
                                organizations.length === 0 &&
                                canCreateOrganizations ? (
                                    <button
                                        type="button"
                                        onClick={() => setCreating(true)}
                                        className="h-[34px] cursor-pointer rounded-[9px] border-0 bg-primary px-4 text-[12.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108"
                                    >
                                        Create your first organization
                                    </button>
                                ) : undefined
                            }
                            title={
                                organizations.length === 0
                                    ? 'No organizations yet'
                                    : 'Nothing matches that search'
                            }
                            description={
                                organizations.length !== 0
                                    ? undefined
                                    : canCreateOrganizations
                                      ? 'An organization holds your projects, their environments and every variable inside them. You start with every permission and grant others exactly what they need.'
                                      : 'Nothing here is discoverable - an organization appears once someone invites you into it.'
                            }
                        />
                    ) : (
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(250px,1fr))] gap-3">
                            {shown.map((organization) => (
                                <OrganizationCard
                                    key={organization.slug}
                                    organization={organization}
                                    onRename={() => setRenaming(organization)}
                                    onDelete={() => setDeleting(organization)}
                                />
                            ))}
                        </div>
                    )}

                    {recentActivity.length > 0 && (
                        <div className="mt-6 overflow-hidden rounded-xl border border-line bg-panel">
                            <div className="flex items-center gap-2 border-b border-line px-[15px] py-3">
                                <div className="text-[13px] font-semibold">
                                    Your recent activity
                                </div>
                                <div className="text-[11.5px] text-fg-3">
                                    what you did, everywhere you did it
                                </div>
                            </div>
                            <ActionTrail
                                entries={recentActivity}
                                emptyLabel="Nothing yet."
                            />
                        </div>
                    )}

                    {organizations.some((o) => o.canUpdate || o.canDelete) && (
                        <p className="mt-3 text-[11.5px] text-fg-3">
                            Right-click an organization to rename or delete it,
                            where your grants allow.
                        </p>
                    )}
                </div>
            </div>

            <CreateDialog
                open={creating}
                onOpenChange={setCreating}
                title="New organization"
                description="You start holding every permission and grant others exactly what they need."
                label="Organization name"
                placeholder="Lebify"
                action="/orgs"
            />

            {renaming && (
                <RenameDialog
                    open
                    onOpenChange={(next) => !next && setRenaming(null)}
                    title={`Rename ${renaming.name}`}
                    description="The URL follows the name, so links already shared will stop resolving."
                    label="Organization name"
                    current={renaming.name}
                    action={`/orgs/${renaming.slug}`}
                />
            )}

            {deleting && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setDeleting(null)}
                    title={`Delete ${deleting.name}?`}
                    description="This cannot be undone, and it happens for everyone in it, not just you."
                    confirmWord={deleting.name}
                    consequences={[
                        `All ${deleting.projects} project${deleting.projects === 1 ? '' : 's'} are destroyed, including any already deleted.`,
                        'Every environment, variable, value and version inside them goes with it.',
                        `All ${deleting.members} member${deleting.members === 1 ? '' : 's'} lose access, along with every invitation and reveal PIN.`,
                        'The audit log is kept - it is append-only, and a record that erases itself is not a record.',
                    ]}
                    action={`/orgs/${deleting.slug}`}
                    onDeleted={() => setDeleting(null)}
                />
            )}
        </AppShell>
    );
}
