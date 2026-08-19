import { router } from '@inertiajs/react';
import { useState } from 'react';
import { GrantChecklist } from '@/components/vault';
import type { GrantRow } from '@/lib/access';
import { flash as go } from '@/lib/visit';
import type { AdminPerson, ProjectAdmin, ProjectSummary } from '../types';

/**
 * One member's access to THIS project, as the same checklist used everywhere
 * grants are edited. Organization-wide rows show locked for context - they are
 * edited from the organization's people screen, not from inside one project.
 */
export function MemberAccessCard({
    member,
    admin,
    project,
}: {
    member: AdminPerson;
    admin: ProjectAdmin;
    project: ProjectSummary;
}) {
    const [rows, setRows] = useState<GrantRow[]>(() => toRows(member, project));
    const [busy, setBusy] = useState(false);

    const save = () => {
        setBusy(true);

        router.put(
            `/orgs/${project.organizationSlug}/members/${member.id}/grants`,
            {
                project_id: project.id,
                // Only this project's rows travel; the org rows in `rows` are
                // display context and the server would refuse them here anyway.
                grants: rows.filter((row) => row.project_id === project.id),
            },
            {
                ...go(`Access updated for ${member.email}`),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="overflow-hidden rounded-xl border border-line bg-panel">
            <div className="flex items-center gap-2 border-b border-line px-[15px] py-3">
                <div className="text-[13px] font-semibold">
                    {member.name}
                    <span className="ml-2 text-[11px] font-normal text-fg-3">
                        in {project.name}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={save}
                    disabled={busy}
                    className="ml-auto h-[26px] cursor-pointer rounded-lg border-0 bg-primary px-2.5 text-[11.5px] font-semibold text-primary-foreground transition-[filter] hover:brightness-108 disabled:cursor-progress disabled:opacity-70"
                >
                    {busy ? 'Saving…' : 'Save access'}
                </button>
            </div>

            <div className="flex flex-col gap-3 p-[15px]">
                <GrantChecklist
                    scopes={[
                        {
                            id: project.id,
                            slug: project.slug,
                            name: project.name,
                            environments: admin.environments.map(
                                (environment) => ({
                                    id: environment.id,
                                    slug: environment.slug,
                                    name: environment.name,
                                }),
                            ),
                        },
                    ]}
                    value={rows}
                    onChange={setRows}
                    editableProjectId={project.id}
                />

                {member.grants.organization.length > 0 && (
                    <div className="text-[11px] text-fg-3">
                        Also holds {member.grants.organization.length}{' '}
                        organization-wide grant
                        {member.grants.organization.length === 1 ? '' : 's'} -
                        edited from the organization's Members screen.
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * The wire rows for this member: org rows ride along so the checklist can
 * show covered boxes, but only this project's rows are ever sent back.
 */
function toRows(member: AdminPerson, project: ProjectSummary): GrantRow[] {
    return [
        ...member.grants.organization.map((permission) => ({
            permission,
            project_id: null,
            environment_id: null,
        })),
        ...member.grants.project.map((permission) => ({
            permission,
            project_id: project.id,
            environment_id: null,
        })),
        ...Object.entries(member.grants.environments).flatMap(
            ([environmentId, permissions]) =>
                (permissions ?? []).map((permission) => ({
                    permission,
                    project_id: project.id,
                    environment_id: Number(environmentId),
                })),
        ),
    ];
}
