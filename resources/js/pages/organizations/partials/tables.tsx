import { Link } from '@inertiajs/react';
import { EmptyState, GuardedButton, KindPill } from '@/components/vault';
import { cn } from '@/lib/utils';
import type { OrgProject, OrganizationSummary } from '../types';

const projectGrid =
    'grid grid-cols-[minmax(0,1.3fr)_54px_54px_68px_minmax(0,1.2fr)_104px] gap-3';
const headRow =
    'border-b border-line bg-panel-2 px-[15px] py-[9px] text-[10.5px] font-semibold tracking-[.06em] text-fg-3 uppercase';

export function OrgProjectsScreen({
    projects,
    organization,
    orgSlug,
    query,
    onNew,
}: {
    projects: OrgProject[];
    organization: OrganizationSummary;
    orgSlug: string;
    query: string;
    onNew: () => void;
}) {
    const q = query.toLowerCase();
    const filtered = projects.filter((p) => !q || p.name.includes(q));

    return (
        <div className="w-full px-4 pt-[18px] pb-12 sm:px-7 sm:pt-[22px]">
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Projects
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        One per product or codebase. Counts and health, no
                        charts.
                    </p>
                </div>
                <GuardedButton
                    allowed={organization.canCreateProjects}
                    reason="Creating projects takes the projects.create permission"
                    onClick={onNew}
                    tone="outline"
                    className="ml-auto"
                >
                    New project
                </GuardedButton>
            </div>
            {filtered.length === 0 ? (
                projects.length === 0 ? (
                    <EmptyState
                        art="project"
                        title="No projects yet"
                        description="Create a project to start managing its environment variables."
                    />
                ) : (
                    <EmptyState
                        art="search"
                        title="No projects match your search"
                    />
                )
            ) : (
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    {/* Six columns need more room than a phone has; the table
                        takes its own scrollbar so the page keeps none. */}
                    <div className="overflow-x-auto">
                        <div className="min-w-[640px]">
                            <div className={cn(projectGrid, headRow)}>
                                <div>Project</div>
                                <div>Envs</div>
                                <div>Vars</div>
                                <div>Members</div>
                                <div>Last activity</div>
                                <div>Health</div>
                            </div>
                            {filtered.map((p) => (
                                <Link
                                    key={p.slug}
                                    href={`/orgs/${orgSlug}/projects/${p.slug}`}
                                    className={cn(
                                        projectGrid,
                                        'min-h-[46px] items-center border-b border-line px-[15px] text-inherit transition-colors hover:bg-panel-2',
                                    )}
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="grid size-[22px] shrink-0 place-items-center rounded-md bg-panel-3 text-[9.5px] font-semibold text-fg-2">
                                            {p.initials}
                                        </span>
                                        <span className="truncate font-mono text-xs">
                                            {p.name}
                                        </span>
                                    </div>
                                    <div className="font-mono text-xs text-fg-2">
                                        {p.envs}
                                    </div>
                                    <div className="font-mono text-xs text-fg-2">
                                        {p.vars}
                                    </div>
                                    <div className="font-mono text-xs text-fg-2">
                                        {p.members}
                                    </div>
                                    <div className="truncate text-[11.5px] text-fg-3">
                                        {p.lastActivity ?? ' - '}
                                    </div>
                                    <KindPill
                                        kind={p.missing ? 'fail' : 'ok'}
                                        label={
                                            p.missing
                                                ? `${p.missing} keys missing`
                                                : 'complete'
                                        }
                                    />
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
