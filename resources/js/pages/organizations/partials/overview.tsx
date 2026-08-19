import { Link } from '@inertiajs/react';
import { FolderPlus, History } from 'lucide-react';
import {
    ActivityChart,
    CompositionBar,
    EmptyState,
    InitialsAvatar,
    KindPill,
} from '@/components/vault';
import { cn } from '@/lib/utils';
import type { OrganizationPageProps } from '../types';
import { InvitesCard, MembersCard } from './overview-people';

// The overview gets the data, not the routing concern - `screen` stays with
// the page that owns the tabs.
type Props = Omit<OrganizationPageProps, 'screen'> & {
    onGoProjects: () => void;
    onGoMembers: () => void;
    onNewProject: () => void;
};

function HealthCards({ health }: { health: Props['health'] }) {
    if (health.length === 0) {
        return null;
    }

    return (
        <div className="mb-3.5 grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3">
            {health.map((h) => (
                <div
                    key={h.label}
                    className={cn(
                        'rounded-[11px] border bg-panel px-[15px] py-3.5',
                        h.tone === 'crit'
                            ? 'border-crit-soft'
                            : h.tone === 'sens'
                              ? 'border-sens-soft'
                              : 'border-line',
                    )}
                >
                    <div className="flex items-center gap-2">
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                h.tone === 'crit'
                                    ? 'bg-crit'
                                    : h.tone === 'sens'
                                      ? 'bg-sens'
                                      : 'bg-fg-3',
                            )}
                        />
                        <div className="text-[11px] font-semibold tracking-[.05em] text-fg-3 uppercase">
                            {h.label}
                        </div>
                    </div>
                    <div className="mt-2 flex items-baseline gap-[7px]">
                        <div
                            className={cn(
                                'font-mono text-[25px] font-semibold tracking-tight',
                                h.tone === 'crit'
                                    ? 'text-crit'
                                    : h.tone === 'sens'
                                      ? 'text-sens'
                                      : 'text-fg',
                            )}
                        >
                            {h.value}
                        </div>
                        <div className="text-[11.5px] text-fg-3">{h.sub}</div>
                    </div>
                </div>
            ))}
        </div>
    );
}

function ProjectsGrid({
    projects,
    orgSlug,
    onAllProjects,
    onNewProject,
}: {
    projects: Props['projects'];
    orgSlug: string;
    onAllProjects: () => void;
    onNewProject: () => void;
}) {
    return (
        <div className="overflow-hidden rounded-xl border border-line bg-panel">
            <div className="flex items-center gap-2 border-b border-line px-[15px] py-3">
                <div className="text-[13px] font-semibold">Projects</div>
                <div className="text-[11.5px] text-fg-3">
                    completeness front and center
                </div>
                {projects.length > 0 && (
                    <button
                        type="button"
                        onClick={onAllProjects}
                        className="ml-auto cursor-pointer border-0 bg-transparent text-xs text-primary hover:text-fg"
                    >
                        All projects →
                    </button>
                )}
            </div>
            {projects.length === 0 ? (
                <EmptyState
                    className="border-none"
                    icon={<FolderPlus className="size-4" strokeWidth={1.75} />}
                    title="No projects yet"
                    description="Create a project to start managing its environment variables."
                    action={
                        <button
                            type="button"
                            onClick={onNewProject}
                            className="h-[30px] cursor-pointer rounded-lg border-0 bg-primary px-3 text-[12.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108"
                        >
                            New project
                        </button>
                    }
                />
            ) : (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-px bg-line">
                    {projects.map((p) => (
                        <Link
                            key={p.slug}
                            href={`/orgs/${orgSlug}/projects/${p.slug}`}
                            className="block bg-panel p-[15px] text-inherit transition-colors hover:bg-panel-2"
                        >
                            <div className="mb-[11px] flex items-center gap-2">
                                <span className="grid size-[22px] shrink-0 place-items-center rounded-md bg-panel-3 text-[9.5px] font-semibold text-fg-2">
                                    {p.initials}
                                </span>
                                <span className="font-mono text-[12.5px]">
                                    {p.name}
                                </span>
                                <KindPill
                                    kind={p.missing ? 'fail' : 'ok'}
                                    label={
                                        p.missing
                                            ? `${p.missing} keys missing`
                                            : 'complete'
                                    }
                                    className="ml-auto"
                                />
                            </div>
                            <div className="mb-[11px] flex gap-3.5 text-[11.5px] text-fg-3">
                                <span>{p.envs} envs</span>
                                <span>{p.vars} vars</span>
                                <span>{p.members} members</span>
                            </div>
                            <CompositionBar
                                critical={p.mix[0]}
                                sensitive={p.mix[1]}
                                publicish={p.mix[2]}
                                className="mb-[9px] max-w-40"
                            />
                            {p.lastActivity && (
                                <div className="text-[11px] text-fg-3">
                                    {p.lastActivity}
                                </div>
                            )}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

function ActivityCard({
    activity,
    series,
}: {
    activity: Props['activity'];
    series: Props['activitySeries'];
}) {
    return (
        <div className="overflow-hidden rounded-xl border border-line bg-panel">
            <div className="flex items-center border-b border-line px-[15px] py-3">
                <div className="text-[13px] font-semibold">
                    Organization activity
                </div>
                <div className="ml-auto text-[11px] text-fg-3">
                    filtered to what you can see
                </div>
            </div>
            {/*
                Fourteen days at a glance: failures stack in red on top of
                normal activity, so a bad day is visible before a single row
                is read.
            */}
            <div className="border-b border-line px-[15px] pt-3 pb-1">
                <ActivityChart data={series} />
            </div>
            {activity.length === 0 ? (
                <EmptyState
                    className="m-[15px] border-none"
                    icon={<History className="size-4" strokeWidth={1.75} />}
                    title="No activity yet"
                    description="Reveals, changes, and invites will show up here."
                />
            ) : (
                activity.slice(0, 7).map((a, i) => (
                    <div
                        key={i}
                        className="grid grid-cols-[26px_minmax(0,1fr)_72px] items-start gap-2.5 border-b border-line px-[15px] py-2.5"
                    >
                        <InitialsAvatar initials={a.initials} />
                        <div className="min-w-0">
                            <div className="text-[12.5px]">
                                <span className="font-semibold">{a.actor}</span>{' '}
                                <span className="text-fg-2">{a.action}</span>
                            </div>
                            <div className="mt-[3px] truncate font-mono text-[10.5px] text-fg-3">
                                {a.path}
                            </div>
                        </div>
                        <div className="text-right text-[10.5px] text-fg-3">
                            {a.when}
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

export function OrgOverview({
    organization,
    projects,
    activity,
    activitySeries,
    members,
    invites,
    health,
    onGoProjects,
    onGoMembers,
    onNewProject,
}: Props) {
    const summary = [
        `${projects.length} project${projects.length === 1 ? '' : 's'}`,
        `${members.length} member${members.length === 1 ? '' : 's'}`,
    ];

    if (invites.length > 0) {
        summary.push(
            `${invites.length} pending invite${invites.length === 1 ? '' : 's'}`,
        );
    }

    return (
        <div className="w-full px-4 pt-[20px] pb-11 sm:px-7 sm:pt-[26px]">
            <div className="mb-[22px]">
                <h1 className="mb-1.5 text-[22px] font-semibold tracking-tight">
                    {organization.name}
                </h1>
                <p className="text-[13px] text-fg-2">
                    {summary.join(' · ')}. Everything you have access to.
                </p>
            </div>
            <HealthCards health={health} />
            <div className="grid grid-cols-[minmax(0,1.35fr)_minmax(0,.85fr)] gap-3.5 max-lg:grid-cols-1">
                <div className="flex flex-col gap-3.5">
                    <ProjectsGrid
                        projects={projects}
                        orgSlug={organization.slug}
                        onAllProjects={onGoProjects}
                        onNewProject={onNewProject}
                    />
                    <ActivityCard activity={activity} series={activitySeries} />
                </div>
                <div className="flex flex-col gap-3.5">
                    <InvitesCard
                        invites={invites}
                        orgSlug={organization.slug}
                    />
                    <MembersCard members={members} onManage={onGoMembers} />
                </div>
            </div>
        </div>
    );
}
