import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    FolderGit2,
    Gauge,
    History,
    KeyRound,
    Tags,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import {
    AppShell,
    CreateDialog,
    GuardedButton,
    HeaderSearch,
    ShellHeader,
    SidebarBrand,
    SidebarFooter,
    SidebarHeading,
    SidebarMonoRow,
    SidebarNav,
} from '@/components/vault';
import { useInitials } from '@/hooks/use-initials';
import { useScreen } from '@/hooks/use-screen';
import { dashboard } from '@/routes';
import { ActivityScreen } from './partials/activity-screen';
import { InviteDialog } from './partials/invite-dialog';
import { OrgOverview } from './partials/overview';
import { PeopleScreen } from './partials/people-screen';
import { SharedScreen } from './partials/shared-screen';
import { OrgProjectsScreen } from './partials/tables';
import { TagsScreen } from './partials/tags-screen';
import type { OrganizationPageProps } from './types';

const TITLES: Record<string, string> = {
    home: 'Overview',
    projects: 'Projects',
    members: 'Members',
    tags: 'Tags',
    shared: 'Shared vault',
    activity: 'Activity',
};

export default function OrganizationShow({
    organization,
    projects,
    scopes,
    tags,
    activity,
    activitySeries,
    members,
    invites,
    shared,
    auditLog,
    health,
    screen: initialScreen,
}: OrganizationPageProps) {
    const [screen, setScreen] = useScreen(initialScreen, 'home');
    const [query, setQuery] = useState('');
    const [inviteOpen, setInviteOpen] = useState(false);
    const [projectOpen, setProjectOpen] = useState(false);
    const { auth } = usePage().props;
    const getInitials = useInitials();

    const nav = [
        { id: 'home', label: 'Home', icon: Gauge },
        {
            id: 'projects',
            label: 'Projects',
            icon: FolderGit2,
            badge: projects.length ? String(projects.length) : undefined,
        },
        {
            id: 'members',
            label: 'Members',
            icon: Users,
            badge: members.length ? String(members.length) : undefined,
        },
        {
            id: 'tags',
            label: 'Tags',
            icon: Tags,
            badge: tags.length ? String(tags.length) : undefined,
        },
        {
            id: 'shared',
            label: 'Shared vault',
            icon: KeyRound,
            badge: shared.items.length
                ? String(shared.items.length)
                : undefined,
        },
        {
            id: 'activity',
            label: 'Activity',
            icon: History,
            badge: auditLog.failures
                ? `${auditLog.failures} failed`
                : undefined,
            badgeTone: 'crit' as const,
        },
    ];

    const orgInitials = organization.slug.slice(0, 2).toUpperCase();

    const sidebar = (
        <>
            <SidebarBrand context="org" />
            <div className="px-2.5 pt-3 pb-1.5">
                {/* The way back up, like the project sidebar's card up to its
                    organization. */}
                <Link
                    href={dashboard()}
                    title="All organizations"
                    className="flex w-full items-center gap-2 rounded-[7px] border border-line px-2 py-[7px] text-inherit transition-colors hover:bg-panel-2"
                >
                    <div className="grid size-[18px] place-items-center rounded-[5px] bg-primary-soft text-[9.5px] font-semibold text-primary">
                        {orgInitials}
                    </div>
                    <div className="leading-[1.15]">
                        <div className="text-[12.5px] font-semibold">
                            {organization.name}
                        </div>
                        <div className="text-[10.5px] text-fg-3">
                            {projects.length} project
                            {projects.length === 1 ? '' : 's'} ·{' '}
                            {members.length} member
                            {members.length === 1 ? '' : 's'}
                        </div>
                    </div>
                    <ArrowRight
                        className="ml-auto size-3 text-fg-3"
                        strokeWidth={1.75}
                    />
                </Link>
            </div>
            <SidebarNav items={nav} active={screen} onSelect={setScreen} />
            <SidebarHeading>Projects</SidebarHeading>
            <div className="flex flex-col gap-px overflow-auto px-2.5">
                {projects.length === 0 ? (
                    <div className="px-2 py-1.5 text-[11.5px] text-fg-3">
                        No projects yet
                    </div>
                ) : (
                    projects.map((p) => (
                        <SidebarMonoRow
                            key={p.slug}
                            name={p.name}
                            meta={String(p.vars)}
                            dotClass={p.missing ? 'bg-crit' : 'bg-fg-3'}
                            href={`/orgs/${organization.slug}/projects/${p.slug}`}
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
            <Head title={organization.name} />
            <ShellHeader crumb={organization.slug} active={TITLES[screen]}>
                <div className="flex w-full min-w-0 items-center gap-2 sm:ml-auto sm:w-auto">
                    <HeaderSearch
                        value={query}
                        onChange={setQuery}
                        placeholder="Search projects, people…"
                    />
                    <GuardedButton
                        allowed={organization.canCreateProjects}
                        reason="Creating projects takes the projects.create permission"
                        onClick={() => setProjectOpen(true)}
                        tone="outline"
                    >
                        New project
                    </GuardedButton>
                    <GuardedButton
                        allowed={organization.canInvite}
                        reason="Inviting takes the members.invite permission"
                        onClick={() => setInviteOpen(true)}
                    >
                        Invite by email
                    </GuardedButton>
                </div>
            </ShellHeader>
            <div className="flex-1 overflow-auto">
                {screen === 'home' && (
                    <OrgOverview
                        organization={organization}
                        projects={projects}
                        scopes={scopes}
                        tags={tags}
                        activity={activity}
                        activitySeries={activitySeries}
                        members={members}
                        invites={invites}
                        shared={shared}
                        auditLog={auditLog}
                        health={health}
                        onGoProjects={() => setScreen('projects')}
                        onGoMembers={() => setScreen('members')}
                        onNewProject={() => setProjectOpen(true)}
                    />
                )}
                {screen === 'projects' && (
                    <OrgProjectsScreen
                        projects={projects}
                        organization={organization}
                        orgSlug={organization.slug}
                        query={query}
                        onNew={() => setProjectOpen(true)}
                    />
                )}
                {screen === 'members' && (
                    <PeopleScreen
                        organization={organization}
                        members={members}
                        scopes={scopes}
                        query={query}
                        onInvite={() => setInviteOpen(true)}
                    />
                )}
                {screen === 'tags' && (
                    <TagsScreen
                        organization={organization}
                        scopes={scopes}
                        tags={tags}
                    />
                )}
                {screen === 'shared' && (
                    <SharedScreen
                        organization={organization}
                        shared={shared}
                        query={query}
                    />
                )}
                {screen === 'activity' && (
                    <ActivityScreen
                        organization={organization}
                        auditLog={auditLog}
                    />
                )}
            </div>
            <InviteDialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                orgSlug={organization.slug}
                orgName={organization.name}
                scopes={scopes}
            />
            <CreateDialog
                open={projectOpen}
                onOpenChange={setProjectOpen}
                title="New project"
                description="Three environments - dev, staging and prod - are created with it."
                label="Project name"
                placeholder="Billing API"
                withDescription
                action={`/orgs/${organization.slug}/projects`}
            />
        </AppShell>
    );
}
