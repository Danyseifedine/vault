import { Check } from 'lucide-react';
import { EmptyState, GuardedButton, Locked } from '@/components/vault';
import { cn } from '@/lib/utils';
import type { ProjectAdmin, ProjectSummary } from '../types';
import { MemberAccessCard } from './member-access-card';

/**
 * Members and exactly what each of them reaches.
 *
 * The matrix shows the effective answer per environment action; the card
 * beside it edits the underlying grant rows. Both are refused server-side for
 * anyone without members.manage - this screen only locks the controls.
 */
export function MembersScreen({
    admin,
    permissions,
    grants,
    project,
    selected,
    onSelect,
    onInvite,
}: {
    admin: ProjectAdmin;
    permissions: string[];
    grants: Record<string, number[]>;
    project: ProjectSummary;
    selected: string | null;
    onSelect: (email: string) => void;
    onInvite: () => void;
}) {
    const { people, environments, can } = admin;

    const member =
        people.find((p) => p.email === selected) ?? people[0] ?? null;

    const cell = (v: number | undefined) =>
        v === 3 ? (
            <Check className="size-3.5 text-pub" strokeWidth={2} />
        ) : (
            <span className="text-xs text-fg-3"> - </span>
        );

    const memberGrants = member ? (grants[member.email] ?? []) : [];
    const columns = environments.length;

    return (
        <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <div className="mb-[18px] flex items-end">
                <div>
                    <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                        Members &amp; permissions
                    </h1>
                    <p className="text-[13px] text-fg-2">
                        Default is no access. Every action is its own grant, per
                        scope.
                    </p>
                </div>
                <GuardedButton
                    allowed={can.inviteMembers}
                    reason="Inviting takes the members.invite permission"
                    onClick={onInvite}
                    className="ml-auto"
                >
                    Invite by email
                </GuardedButton>
            </div>

            <div className="grid grid-cols-2 items-start gap-3.5 max-lg:grid-cols-1">
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    <div className="border-b border-line px-[15px] py-3 text-[13px] font-semibold">
                        People
                    </div>
                    {people.length === 0 ? (
                        <EmptyState
                            className="m-[15px] border-none"
                            title="No members yet"
                            description="Invite teammates by email to grant access."
                        />
                    ) : (
                        people.map((p) => (
                            <button
                                key={p.email}
                                type="button"
                                onClick={() => onSelect(p.email)}
                                className={cn(
                                    'flex w-full cursor-pointer items-center gap-2 border-b border-line px-[15px] py-2.5 text-left transition-colors',
                                    member?.email === p.email
                                        ? 'bg-panel-2'
                                        : 'bg-transparent hover:bg-panel-2',
                                )}
                            >
                                <div className="min-w-0">
                                    <div className="truncate text-[12.5px]">
                                        {p.name}
                                    </div>
                                    <div className="truncate text-[11px] text-fg-3">
                                        {p.email}
                                    </div>
                                </div>
                            </button>
                        ))
                    )}
                </div>

                <div className="flex flex-col gap-3.5">
                    <div className="overflow-hidden rounded-xl border border-line bg-panel">
                        {member ? (
                            <>
                                <div className="flex items-center gap-2 border-b border-line px-[15px] py-3">
                                    <span className="text-[13px] font-semibold">
                                        {member.name}
                                    </span>
                                    <span className="text-[11.5px] text-fg-3">
                                        {' '}
                                        - effective grants on {project.name}
                                    </span>
                                </div>
                                {permissions.map((p, i) => (
                                    <div
                                        key={p}
                                        className="grid min-h-9 items-center border-b border-line px-[15px] text-[12.5px]"
                                        style={{
                                            gridTemplateColumns: `minmax(0,1fr) repeat(${columns}, 56px)`,
                                        }}
                                    >
                                        <div className="text-fg-2">{p}</div>
                                        {environments.map((e, c) => (
                                            <div key={e.id}>
                                                {cell(
                                                    memberGrants[
                                                        i * columns + c
                                                    ],
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                ))}
                                <div
                                    className="grid px-[15px] py-2 text-[10.5px] tracking-[.06em] text-fg-3 uppercase"
                                    style={{
                                        gridTemplateColumns: `minmax(0,1fr) repeat(${columns}, 56px)`,
                                    }}
                                >
                                    <div />
                                    {environments.map((e) => (
                                        <div key={e.id}>{e.slug}</div>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <EmptyState
                                className="m-[15px] border-none"
                                title="No member selected"
                                description="Select a person to see their grants."
                            />
                        )}
                    </div>

                    {member && (
                        <Locked
                            when={!can.manageMembers}
                            reason="Editing access takes members.manage"
                        >
                            <MemberAccessCard
                                member={member}
                                admin={admin}
                                project={project}
                            />
                        </Locked>
                    )}
                </div>
            </div>
        </div>
    );
}
