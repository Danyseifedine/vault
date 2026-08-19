import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    CompositionBar,
    EmptyState,
    ProgressThin,
    StatCard,
    TablePagination,
    VariablesTable,
    VersionHistory,
} from '@/components/vault';
import { Field, Panel, Section } from './parts';

/** Tables, stats, history: the read-only face of the product. */
export function DisplaySection() {
    return (
        <Section title="Data display">
            <Panel>
                <div className="grid grid-cols-2 gap-3">
                    <StatCard label="Variables" value={15} meta="in prod" />
                    <StatCard
                        label="Reveals today"
                        value={18}
                        meta="3 denied"
                        metaTone="crit"
                    />
                    <StatCard label="Composition">
                        <CompositionBar
                            className="mt-3.5"
                            critical={5}
                            sensitive={4}
                            publicish={6}
                        />
                    </StatCard>
                    <StatCard label="Progress">
                        <ProgressThin className="mt-4" percent={68} />
                    </StatCard>
                </div>
                <Field label="Skeleton & empty state">
                    <div className="mb-3 flex flex-col gap-[7px]">
                        <Skeleton className="h-2.5 w-2/3" />
                        <Skeleton className="h-2.5 w-2/5" />
                    </div>
                    <EmptyState
                        title="No variables in this group"
                        description="Import a .env or add one manually."
                        action={<Button size="sm">Add variable</Button>}
                    />
                </Field>
            </Panel>
            <Panel>
                <VariablesTable
                    rows={[
                        { key: 'DATABASE_URL', sensitivity: 'critical' },
                        { key: 'REDIS_URL', sensitivity: 'sensitive' },
                        { key: 'LOG_LEVEL', sensitivity: 'public' },
                    ]}
                    onReveal={() =>
                        toast.info(
                            'The reveal dialog lives under Secret inputs.',
                        )
                    }
                    footer={
                        <TablePagination label="1 - 3 of 15" hasPrev={false} />
                    }
                />
                <Field label="Version history">
                    <VersionHistory
                        entries={[
                            { version: 'v7', who: 'dany', when: 'today 14:02' },
                            { version: 'v6', who: 'sami', when: 'Jul 28' },
                            { version: 'v5', who: 'dany', when: 'Jul 11' },
                        ]}
                        onRollback={(e) =>
                            toast.success(
                                `Rolled back to ${e.version} · logged`,
                            )
                        }
                    />
                </Field>
            </Panel>
        </Section>
    );
}
