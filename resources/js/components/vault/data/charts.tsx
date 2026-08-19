import {
    Bar,
    BarChart,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
} from 'recharts';

/**
 * The two charts the product needs, and no others.
 *
 * A chart earns its place by answering a question faster than a table:
 * - ActivityChart: "was there a bad day?" - failures stack in crit red on top
 *   of normal activity, so a spike of wrong PINs reads at a glance.
 * - SensitivityDonut: "how dangerous is this project?" - the split of
 *   critical / sensitive / public keys, the same three colors the dots wear.
 *
 * Colors come from the design tokens so both themes work; counts only, never
 * names, never values.
 */

export interface ActivityDay {
    day: string;
    ok: number;
    fail: number;
}

function ChartTip({
    active,
    payload,
    label,
}: {
    active?: boolean;
    payload?: { name?: string; value?: number; dataKey?: string }[];
    label?: string;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="rounded-lg border border-line-2 bg-panel px-2.5 py-1.5 text-[11px] shadow-[var(--shadow-panel)]">
            {label && <div className="mb-0.5 text-fg-3">{label}</div>}
            {payload.map((entry) => (
                <div key={entry.dataKey ?? entry.name} className="text-fg-2">
                    {entry.name}: <span className="text-fg">{entry.value}</span>
                </div>
            ))}
        </div>
    );
}

/** Fourteen days of audit entries, failures stacked on top in crit. */
export function ActivityChart({ data }: { data: ActivityDay[] }) {
    const empty = data.every((d) => d.ok === 0 && d.fail === 0);

    if (empty) {
        return (
            <div className="grid h-[120px] place-items-center text-[11.5px] text-fg-3">
                Nothing yet - activity will draw itself here.
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={120}>
            <BarChart
                data={data}
                margin={{ top: 4, right: 0, bottom: 0, left: 0 }}
                barCategoryGap="25%"
            >
                <XAxis
                    dataKey="day"
                    tickLine={false}
                    axisLine={false}
                    interval="preserveStartEnd"
                    tick={{ fontSize: 10, fill: 'var(--fg-3)' }}
                />
                <Tooltip
                    cursor={{ fill: 'var(--panel-2)' }}
                    content={<ChartTip />}
                />
                <Bar
                    dataKey="ok"
                    name="entries"
                    stackId="day"
                    fill="var(--primary)"
                    fillOpacity={0.55}
                    radius={[0, 0, 0, 0]}
                />
                <Bar
                    dataKey="fail"
                    name="failures"
                    stackId="day"
                    fill="var(--crit)"
                    radius={[3, 3, 0, 0]}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}

const SENSITIVITY_SLICES = [
    { key: 'critical', color: 'var(--crit)' },
    { key: 'sensitive', color: 'var(--sens)' },
    { key: 'public', color: 'var(--pub)' },
] as const;

/** The sensitivity split of a set of variables, as a small donut. */
export function SensitivityDonut({
    critical,
    sensitive,
    publicish,
}: {
    critical: number;
    sensitive: number;
    publicish: number;
}) {
    const total = critical + sensitive + publicish;

    if (total === 0) {
        return (
            <div className="grid h-[96px] place-items-center text-[11.5px] text-fg-3">
                No variables yet.
            </div>
        );
    }

    const data = [
        { name: 'critical', value: critical },
        { name: 'sensitive', value: sensitive },
        { name: 'public', value: publicish },
    ];

    return (
        <div className="flex items-center gap-4">
            <div className="relative size-[96px] shrink-0">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={data}
                            dataKey="value"
                            innerRadius={30}
                            outerRadius={46}
                            paddingAngle={2}
                            strokeWidth={0}
                            isAnimationActive={false}
                        >
                            {SENSITIVITY_SLICES.map((slice) => (
                                <Cell key={slice.key} fill={slice.color} />
                            ))}
                        </Pie>
                        <Tooltip content={<ChartTip />} />
                    </PieChart>
                </ResponsiveContainer>
                <div className="pointer-events-none absolute inset-0 grid place-items-center text-[13px] font-semibold">
                    {total}
                </div>
            </div>
            <div className="flex flex-col gap-1 text-[11.5px]">
                {SENSITIVITY_SLICES.map((slice, index) => (
                    <div key={slice.key} className="flex items-center gap-1.5">
                        <span
                            className="size-2 rounded-[3px]"
                            style={{ background: slice.color }}
                        />
                        <span className="text-fg-2">{slice.key}</span>
                        <span className="ml-1 font-mono text-fg-3">
                            {data[index].value}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
