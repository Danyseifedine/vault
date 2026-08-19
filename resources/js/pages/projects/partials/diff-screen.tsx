import { useEffect, useState } from 'react';
import { EmptyState, Select, SensitivityDot } from '@/components/vault';
import type { Sensitivity } from '@/components/vault';
import { cn } from '@/lib/utils';
import type { ProjectEnvironment } from '../types';

const grid = 'grid grid-cols-[minmax(150px,1.4fr)_124px] gap-3';

interface DiffRow {
    key: string;
    sensitivity: Sensitivity;
    status: 'missing' | 'differs' | 'in sync';
}

function EnvPicker({
    environments,
    value,
    onChange,
    label,
}: {
    environments: ProjectEnvironment[];
    value: string;
    onChange: (next: string) => void;
    label: string;
}) {
    return (
        <Select
            mono
            label={label}
            value={value}
            onChange={onChange}
            className="w-auto"
            prefix={
                <span className="shrink-0 text-[10.5px] tracking-[.06em] text-fg-3 uppercase">
                    {label}
                </span>
            }
            options={environments.map((e) => ({ value: e.id, label: e.id }))}
        />
    );
}

/**
 * Compares two environments.
 *
 * The comparison happens on the SERVER: values are masked in the page props,
 * so anything computed here would report "in sync" for every secret whether it
 * matched or not. The endpoint returns a status per key and never a value,
 * which is what makes a diff safe to show to someone who cannot reveal.
 */
export function DiffScreen({
    environments,
    basePath,
}: {
    environments: ProjectEnvironment[];
    basePath: string;
}) {
    const [left, setLeft] = useState(environments[0]?.id ?? '');
    const [right, setRight] = useState(
        environments[environments.length - 1]?.id ?? '',
    );
    // One piece of state, written only from async callbacks - a synchronous
    // setState inside an effect cascades renders, so "loading" is derived from
    // whether the answer we hold matches the comparison being asked for.
    const [result, setResult] = useState<{
        for: string;
        rows: DiffRow[];
        state: 'idle' | 'refused' | 'failed';
    }>({
        for: '',
        rows: [],
        state: 'idle',
    });

    const comparable = environments.length >= 2 && left !== right;
    const asked = `${left}→${right}`;
    const loading = comparable && result.for !== asked;

    useEffect(() => {
        if (!comparable) {
            return;
        }

        let cancelled = false;

        fetch(
            `${basePath}/diff?left=${encodeURIComponent(left)}&right=${encodeURIComponent(right)}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        )
            .then(async (response) => {
                if (cancelled) {
                    return;
                }

                if (response.status === 403) {
                    setResult({ for: asked, rows: [], state: 'refused' });

                    return;
                }

                if (!response.ok) {
                    setResult({ for: asked, rows: [], state: 'failed' });

                    return;
                }

                const body = await response.json();

                setResult({ for: asked, rows: body.rows ?? [], state: 'idle' });
            })
            .catch(
                () =>
                    !cancelled &&
                    setResult({ for: asked, rows: [], state: 'failed' }),
            );

        return () => {
            cancelled = true;
        };
    }, [basePath, left, right, asked, comparable]);

    const rows = result.for === asked ? result.rows : [];
    const state = result.for === asked ? result.state : 'idle';

    if (environments.length < 2) {
        return (
            <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
                <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                    Environment diff
                </h1>
                <p className="mb-[18px] text-[13px] text-fg-2">
                    Which keys does one environment have that another is missing
                    - before it becomes an incident.
                </p>
                <EmptyState
                    title="Need at least two environments to compare"
                    description="Add a second environment to run a completeness check."
                />
            </div>
        );
    }

    const missingCount = rows.filter((r) => r.status === 'missing').length;

    return (
        <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                Environment diff
            </h1>
            <p className="mb-[18px] text-[13px] text-fg-2">
                Compared on the server - you learn that two values differ, never
                what either one is.
            </p>
            <div className="mb-4 flex items-center gap-2.5">
                <EnvPicker
                    environments={environments}
                    value={left}
                    onChange={setLeft}
                    label="from"
                />
                <span className="text-fg-3">→</span>
                <EnvPicker
                    environments={environments}
                    value={right}
                    onChange={setRight}
                    label="to"
                />
                {missingCount > 0 && (
                    <div className="ml-auto flex items-center gap-2 rounded-lg border border-crit-soft bg-crit-soft px-2.5 py-[5px] text-xs text-crit">
                        {missingCount} key{missingCount === 1 ? '' : 's'}{' '}
                        missing in {right}
                    </div>
                )}
            </div>

            {state === 'refused' ? (
                <EmptyState
                    title="You cannot see both environments"
                    description="A diff tells you something about each side, so it takes view access to both."
                />
            ) : state === 'failed' ? (
                <EmptyState
                    title="Could not load the diff"
                    description="Something went wrong reaching the server."
                />
            ) : left === right ? (
                <EmptyState
                    title="Pick two different environments"
                    description="Comparing an environment with itself says nothing."
                />
            ) : rows.length === 0 ? (
                <EmptyState
                    title={loading ? 'Comparing…' : 'No variables yet'}
                    description={
                        loading
                            ? ''
                            : 'Import a .env or add one manually to run a diff.'
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    <div
                        className={cn(
                            grid,
                            'border-b border-line bg-panel-2 px-[15px] py-[9px] text-[10.5px] font-semibold tracking-[.06em] text-fg-3 uppercase',
                        )}
                    >
                        <div>Key</div>
                        <div>
                            {left} → {right}
                        </div>
                    </div>
                    {rows.map((d) => (
                        <div
                            key={d.key}
                            className={cn(
                                grid,
                                'min-h-10 items-center border-b border-line px-[15px] transition-colors hover:bg-panel-2',
                            )}
                        >
                            <div className="flex items-center gap-2 font-mono text-xs">
                                <SensitivityDot level={d.sensitivity} />
                                {d.key}
                            </div>
                            <div
                                className={cn(
                                    'inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[10.5px]',
                                    d.status === 'missing'
                                        ? 'bg-crit-soft text-crit'
                                        : d.status === 'differs'
                                          ? 'bg-sens-soft text-sens'
                                          : 'bg-pub-soft text-pub',
                                )}
                            >
                                {d.status}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
