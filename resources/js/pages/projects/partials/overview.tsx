import {
    CompositionBar,
    SensitivityDonut,
    EmptyState,
    KIND_TONES,
    StatCard,
} from '@/components/vault';
import { cn } from '@/lib/utils';
import type { ProjectPageProps } from '../types';
import { variablesIn } from './helpers';

export function ProjectOverview({
    project,
    environments,
    variables,
    recentActivity,
    env,
    onGoDiff,
    onGoAudit,
    onOpenEnv,
}: Pick<
    ProjectPageProps,
    'project' | 'environments' | 'variables' | 'recentActivity'
> & {
    env: string;
    onGoDiff: () => void;
    onGoAudit: () => void;
    onOpenEnv: (env: string) => void;
}) {
    const list = variablesIn(variables, env);
    const stats = [
        { label: 'Variables', value: String(list.length), meta: `in ${env}` },
        {
            label: 'Critical',
            value: String(
                list.filter((v) => v.sensitivity === 'critical').length,
            ),
            meta: 'PIN + password',
        },
    ];

    return (
        <div className="w-full px-4 pt-[20px] pb-10 sm:px-7 sm:pt-[26px]">
            <div className="mb-[22px] flex items-end justify-between gap-5">
                <div>
                    <h1 className="mb-1.5 text-[22px] font-semibold tracking-tight">
                        Project overview
                    </h1>
                    <p className="max-w-[520px] text-[13px] text-pretty text-fg-2">
                        Every variable {project.name} ships with - encrypted at
                        rest, scoped per app, versioned, audited.
                    </p>
                </div>
                {environments.length > 1 && (
                    <button
                        type="button"
                        onClick={onGoDiff}
                        className="h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-[11px] text-[12.5px] transition-colors hover:bg-panel-2"
                    >
                        Completeness check
                    </button>
                )}
            </div>

            <div className="mb-[26px] grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3">
                {stats.map((s) => (
                    <StatCard
                        key={s.label}
                        label={s.label}
                        value={s.value}
                        meta={s.meta}
                    />
                ))}
                {/*
                    The donut answers "how dangerous is this environment" in
                    one look: how much of it would hurt if it leaked.
                */}
                <StatCard label={`Sensitivity in ${env}`}>
                    <div className="mt-2">
                        <SensitivityDonut
                            critical={
                                list.filter((v) => v.sensitivity === 'critical')
                                    .length
                            }
                            sensitive={
                                list.filter(
                                    (v) => v.sensitivity === 'sensitive',
                                ).length
                            }
                            publicish={
                                list.filter((v) => v.sensitivity === 'public')
                                    .length
                            }
                        />
                    </div>
                </StatCard>
            </div>

            <div className="grid grid-cols-[minmax(0,1.25fr)_minmax(0,.85fr)] gap-3.5 max-lg:grid-cols-1">
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    <div className="flex items-center gap-2 border-b border-line px-[15px] py-[13px]">
                        <div className="text-[13px] font-semibold">
                            Environments
                        </div>
                        <div className="text-[11.5px] text-fg-3">
                            values are isolated per environment
                        </div>
                    </div>
                    {environments.length === 0 ? (
                        <EmptyState
                            className="m-[15px] border-none"
                            title="No environments yet"
                            description="Add dev, staging, or prod to start storing values."
                        />
                    ) : (
                        environments.map((e) => {
                            const inEnv = variablesIn(variables, e.id);
                            const missing = variables.length - inEnv.length;

                            return (
                                <button
                                    key={e.id}
                                    type="button"
                                    onClick={() => onOpenEnv(e.id)}
                                    className="flex w-full cursor-pointer items-center gap-2.5 overflow-hidden border-0 border-b border-line bg-transparent px-[15px] py-3 transition-colors hover:bg-panel-2"
                                >
                                    <span
                                        className={cn(
                                            'size-[7px] shrink-0 rounded-full',
                                            e.dotClass,
                                        )}
                                    />
                                    <span className="w-[66px] shrink-0 text-left font-mono text-[12.5px]">
                                        {e.id}
                                    </span>
                                    <span className="shrink-0 text-xs whitespace-nowrap text-fg-2">
                                        {inEnv.length} vars
                                    </span>
                                    <CompositionBar
                                        className="min-w-0 flex-1"
                                        critical={
                                            inEnv.filter(
                                                (v) =>
                                                    v.sensitivity ===
                                                    'critical',
                                            ).length
                                        }
                                        sensitive={
                                            inEnv.filter(
                                                (v) =>
                                                    v.sensitivity ===
                                                    'sensitive',
                                            ).length
                                        }
                                        publicish={
                                            inEnv.filter(
                                                (v) =>
                                                    v.sensitivity === 'public',
                                            ).length
                                        }
                                    />
                                    <span
                                        className={cn(
                                            'shrink-0 text-right text-[11.5px] whitespace-nowrap',
                                            missing ? 'text-crit' : 'text-fg-3',
                                        )}
                                    >
                                        {missing
                                            ? `${missing} missing`
                                            : 'complete'}
                                    </span>
                                    <span className="text-xs text-fg-3">→</span>
                                </button>
                            );
                        })
                    )}
                    {environments.length > 0 && (
                        <div className="flex gap-3.5 border-t border-line px-[15px] py-[11px] text-[11.5px] text-fg-3">
                            <span className="inline-flex items-center gap-[5px]">
                                <span className="size-[7px] rounded-[2px] bg-crit" />
                                Critical
                            </span>
                            <span className="inline-flex items-center gap-[5px]">
                                <span className="size-[7px] rounded-[2px] bg-sens" />
                                Sensitive
                            </span>
                            <span className="inline-flex items-center gap-[5px]">
                                <span className="size-[7px] rounded-[2px] bg-pub" />
                                Public-ish
                            </span>
                        </div>
                    )}
                </div>

                <div className="flex flex-col overflow-hidden rounded-xl border border-line bg-panel">
                    <div className="flex items-center border-b border-line px-[15px] py-[13px]">
                        <div className="text-[13px] font-semibold">
                            Recent activity
                        </div>
                        {recentActivity.length > 0 && (
                            <button
                                type="button"
                                onClick={onGoAudit}
                                className="ml-auto cursor-pointer border-0 bg-transparent text-xs text-primary hover:text-fg"
                            >
                                Audit log →
                            </button>
                        )}
                    </div>
                    {recentActivity.length === 0 ? (
                        <EmptyState
                            className="m-[15px] border-none"
                            title="No activity yet"
                            description="Reveals, changes, and imports will show up here."
                        />
                    ) : (
                        recentActivity.slice(0, 5).map((a, i) => (
                            <div
                                key={i}
                                className="flex items-start gap-2.5 border-b border-line px-[15px] py-2.5"
                            >
                                <span
                                    className={cn(
                                        'mt-[5px] size-1.5 shrink-0 rounded-full',
                                        KIND_TONES[a.kind].dot,
                                    )}
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="text-[12.5px]">
                                        <span className="font-semibold">
                                            {a.actor}
                                        </span>{' '}
                                        <span className="text-fg-2">
                                            {a.action}
                                        </span>
                                    </div>
                                    <div className="mt-[3px] truncate font-mono text-[10.5px] text-fg-3">
                                        {a.path}
                                    </div>
                                </div>
                                <div className="text-[10.5px] whitespace-nowrap text-fg-3">
                                    {a.time}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}
