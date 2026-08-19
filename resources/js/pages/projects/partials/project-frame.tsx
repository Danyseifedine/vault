import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRight,
    Gauge,
    History,
    KeyRound,
    Settings,
    Upload,
    Users,
} from 'lucide-react';
import {
    SidebarBrand,
    SidebarFooter,
    SidebarHeading,
    SidebarMonoRow,
    SidebarNav,
} from '@/components/vault';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ProjectPageProps } from '../types';

/** The project page's frame pieces: its sidebar and the environment switcher. */

export function ProjectSidebar({
    project,
    variables,
    auditLog,
    screen,
    onSelect,
}: Pick<ProjectPageProps, 'project' | 'variables' | 'auditLog'> & {
    screen: string;
    onSelect: (screen: string) => void;
}) {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    // Counts what the Variables screen lists: every key the project defines,
    // set or not. Per-environment completeness lives on the overview.
    const varCount = variables.length;

    // The groups as the variables themselves report them - a table of contents
    // for the keys, which is all a group has ever been.
    const groups = [...new Set(variables.map((v) => v.group))].sort();

    const nav = [
        { id: 'overview', label: 'Overview', icon: Gauge },
        {
            id: 'vars',
            label: 'Variables',
            icon: KeyRound,
            badge: varCount ? String(varCount) : undefined,
        },
        { id: 'diff', label: 'Diff', icon: ArrowLeftRight },
        {
            id: 'audit',
            label: 'Audit log',
            icon: History,
            badge: auditLog.total ? String(auditLog.total) : undefined,
        },
        { id: 'members', label: 'Members', icon: Users },
        { id: 'import', label: 'Import', icon: Upload },
        { id: 'settings', label: 'Settings', icon: Settings },
    ];

    return (
        <>
            <SidebarBrand context="project" />
            <div className="px-2.5 pt-3 pb-1.5">
                <Link
                    href={`/orgs/${project.organizationSlug}`}
                    title="Organization dashboard"
                    className="flex w-full items-center gap-2 rounded-[7px] border border-line px-2 py-[7px] text-inherit transition-colors hover:bg-panel-2"
                >
                    <div className="grid size-[18px] place-items-center rounded-[5px] bg-panel-3 text-[9.5px] font-semibold text-fg-2">
                        {project.slug.slice(0, 2).toUpperCase()}
                    </div>
                    <div className="leading-[1.15]">
                        <div className="text-[12.5px] font-semibold">
                            {project.name}
                        </div>
                        <div className="text-[10.5px] text-fg-3">
                            {project.organizationName}
                        </div>
                    </div>
                    <ArrowRight
                        className="ml-auto size-3 text-fg-3"
                        strokeWidth={1.75}
                    />
                </Link>
            </div>
            <SidebarNav items={nav} active={screen} onSelect={onSelect} />
            <SidebarHeading>Groups</SidebarHeading>
            <div className="flex flex-col gap-px overflow-auto px-2.5">
                {groups.length === 0 ? (
                    <div className="px-2 py-1.5 text-[11.5px] text-fg-3">
                        No variables yet
                    </div>
                ) : (
                    groups.map((name) => (
                        <SidebarMonoRow
                            key={name}
                            name={name}
                            meta={String(
                                variables.filter((v) => v.group === name)
                                    .length,
                            )}
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
}

export function EnvTabs({
    environments,
    env,
    onChange,
}: {
    environments: ProjectPageProps['environments'];
    env: string;
    onChange: (env: string) => void;
}) {
    if (environments.length === 0) {
        return null;
    }

    return (
        <div className="ml-4 flex items-center gap-0.5 rounded-lg border border-line bg-panel-2 p-0.5">
            {environments.map((e) => (
                <button
                    key={e.id}
                    type="button"
                    onClick={() => onChange(e.id)}
                    className={cn(
                        'inline-flex h-6 cursor-pointer items-center rounded-md border-0 px-2.5 font-mono text-xs transition-colors',
                        env === e.id
                            ? 'bg-panel text-fg shadow-sm'
                            : 'bg-transparent text-fg-3 hover:text-fg-2',
                    )}
                >
                    <span
                        className={cn(
                            'mr-1.5 inline-block size-1.5 rounded-full',
                            e.dotClass,
                        )}
                    />
                    {e.id}
                </button>
            ))}
        </div>
    );
}
