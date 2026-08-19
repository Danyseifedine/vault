/**
 * The permission vocabulary, mirrored from app/Enums/Permission.php - names,
 * labels, and which scope each is native to. The checklist and both member
 * editors all read from here, so the catalogue exists once on this side too.
 */

export type GrantRow = {
    permission: string;
    project_id?: number | null;
    environment_id?: number | null;
};

export const ENV_ACTIONS = [
    { value: 'variables.view', label: 'View variables' },
    { value: 'variables.reveal', label: 'Reveal values' },
    { value: 'variables.export', label: 'Export .env' },
    { value: 'variables.create', label: 'Create variables' },
    { value: 'variables.update', label: 'Update values' },
    { value: 'variables.rollback', label: 'Roll back values' },
    { value: 'variables.import', label: 'Import .env' },
    { value: 'variables.delete', label: 'Delete variables' },
] as const;

export const PROJECT_PERMISSIONS = [
    { value: 'settings.update', label: 'Change project settings' },
    { value: 'environments.manage', label: 'Manage environments' },
    { value: 'groups.manage', label: 'Manage groups' },
    { value: 'tags.create', label: 'Create tags' },
] as const;

export const ORG_PERMISSIONS = [
    { value: 'organization.update', label: 'Rename the organization' },
    { value: 'organization.delete', label: 'Delete the organization' },
    { value: 'projects.create', label: 'Create projects' },
    { value: 'members.invite', label: 'Invite members' },
    { value: 'members.manage', label: 'Manage member access' },
    { value: 'audit.view-all', label: "Read everyone's activity" },
    { value: 'audit.wipe', label: 'Wipe the activity log' },
    { value: 'shared.view', label: 'See the shared vault' },
    { value: 'shared.reveal', label: 'Reveal shared vault items' },
    { value: 'shared.manage', label: 'Add and remove shared vault items' },
    { value: 'pins.manage', label: 'Manage reveal PINs' },
    { value: 'tags.create-global', label: 'Create organization-wide tags' },
] as const;

/** One stable identity per (permission, scope) - what sets and maps key on. */
export const grantKey = (row: GrantRow): string =>
    `${row.permission}|${row.project_id ?? 0}|${row.environment_id ?? 0}`;

export const hasGrant = (
    rows: GrantRow[],
    permission: string,
    projectId?: number | null,
    environmentId?: number | null,
): boolean =>
    rows.some(
        (row) =>
            row.permission === permission &&
            (row.project_id ?? 0) === (projectId ?? 0) &&
            (row.environment_id ?? 0) === (environmentId ?? 0),
    );

/**
 * Is this (permission, scope) already covered by a WIDER row in the set?
 * An org-wide row covers everything; a project row covers its environments.
 * The checklist renders covered boxes checked-and-locked, so a narrower
 * duplicate can never be written.
 */
export const coveredByWider = (
    rows: GrantRow[],
    permission: string,
    projectId?: number | null,
    environmentId?: number | null,
): boolean => {
    if (projectId == null && environmentId == null) {
        return false;
    }

    if (hasGrant(rows, permission, null, null)) {
        return true;
    }

    return environmentId != null && projectId != null
        ? hasGrant(rows, permission, projectId, null)
        : false;
};

/** Add or remove one (permission, scope) - the checkbox click. */
export const toggleGrant = (
    rows: GrantRow[],
    permission: string,
    projectId?: number | null,
    environmentId?: number | null,
): GrantRow[] => {
    const next: GrantRow = {
        permission,
        project_id: projectId ?? null,
        environment_id: environmentId ?? null,
    };

    return hasGrant(rows, permission, projectId, environmentId)
        ? rows.filter((row) => grantKey(row) !== grantKey(next))
        : [...rows, next];
};

/** Replace every row belonging to one scope with the given permission list. */
export const setScope = (
    rows: GrantRow[],
    permissions: string[],
    projectId?: number | null,
    environmentId?: number | null,
): GrantRow[] => [
    ...rows.filter(
        (row) =>
            (row.project_id ?? 0) !== (projectId ?? 0) ||
            (row.environment_id ?? 0) !== (environmentId ?? 0),
    ),
    ...permissions.map((permission) => ({
        permission,
        project_id: projectId ?? null,
        environment_id: environmentId ?? null,
    })),
];
