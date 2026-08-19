import type { AuditRow, Sensitivity } from '@/components/vault';

// Prop types for the project dashboard - mirrors the eventual backend DTOs.
// No sample data lives here; pages render real Inertia props (empty until the
// backend exists).

export interface ProjectVariable {
    id: number;
    key: string;
    group: string;
    sensitivity: Sensitivity;
    unsafe?: boolean;
    tags: string[];
    /** One entry per environment the variable has a value in; keyed by environment id. */
    values: Partial<Record<string, string>>;
    /**
     * Editing the definition and deleting reach every environment the variable
     * lives in, so the server answers them per row rather than per environment.
     */
    canEdit: boolean;
    canDelete: boolean;
}

/** What a reveal costs, mirroring App\Enums\RevealRequirement. */
export type RevealRequirement = 'none' | 'pin' | 'pin_password';

/**
 * This environment's reveal matrix, per sensitivity. Null when the viewer
 * holds no `variables.reveal` here - there is no cost to quote someone who
 * cannot read the value at all.
 */
export type RevealPolicies = Record<
    'critical' | 'sensitive' | 'public',
    RevealRequirement
> | null;

/** What the viewer may do inside one environment. */
export interface EnvironmentAbilities {
    create: boolean;
    update: boolean;
    rollback: boolean;
    reveal: boolean;
    export: boolean;
    import: boolean;
    policies: RevealPolicies;
}

/**
 * One page of this project's audit log. Paged and filtered in SQL - the log is
 * the only unbounded table here, and the environment rule that hides other
 * people's prod history runs in the query, not after it.
 */
export interface ProjectAuditPage {
    rows: AuditRow[];
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
    filter: string;
    failures: number;
}

/** The viewer's own reach in this project, so the UI locks what it must. */
export interface ProjectViewer {
    /** Keyed by environment slug - the same key the tabs use. */
    environments: Partial<Record<string, EnvironmentAbilities>>;
    canTag: boolean;
    /** `audit.wipe` - the one power here that destroys rather than reads. */
    canWipeActivity: boolean;
}

export interface ProjectEnvironment {
    id: string;
    dotClass: string;
}

export interface ProjectMember {
    name: string;
    email: string;
    initials: string;
}

export interface ProjectSummary {
    id: number;
    organizationSlug: string;
    organizationName: string;
    slug: string;
    name: string;
    description: string | null;
}

export interface AdminEnvironment {
    id: number;
    slug: string;
    name: string;
    position: number;
    /** sensitivity → requirement (none / pin / pin_password). */
    policies: Record<string, string>;
}

export interface AdminNamed {
    id: number;
    slug: string;
    name: string;
    variables: number;
}

export interface AdminTag {
    id: number;
    name: string;
    projectId: number | null;
    environmentId: number | null;
    scope: 'organization' | 'project' | 'environment';
}

export interface AdminPerson {
    id: number;
    name: string;
    email: string;
    /**
     * The raw grant rows reaching this project, split by scope. Organization
     * rows are context - the project screen shows them locked, never edits them.
     */
    grants: {
        organization: string[];
        project: string[];
        environments: Partial<Record<string, string[]>>;
    };
}

export interface ProjectAdmin {
    can: Record<string, boolean>;
    /** Permission names in the same order as `permissions` labels. */
    permissionKeys: string[];
    settings: {
        auditViews: boolean;
        pinMaxAttempts: number;
        pinLockoutMinutes: number;
    };
    environments: AdminEnvironment[];
    groups: AdminNamed[];
    tags: AdminTag[];
    people: AdminPerson[];
}

export interface ProjectPageProps {
    project: ProjectSummary;
    environments: ProjectEnvironment[];
    variables: ProjectVariable[];
    members: ProjectMember[];
    /** Permission labels shown as matrix rows (e.g. "View variables", "Reveal values"). */
    permissions: string[];
    /**
     * Effective environment actions per MEMBER, keyed by email. A wider grant
     * (project- or organization-wide) lights every environment's cell.
     *
     * Flattened as permission × environment, in the order of `permissions` and
     * `environments`: 3 = granted, 0 = not.
     */
    grants: Record<string, number[]>;
    auditLog: ProjectAuditPage;
    /** The newest few, unpaged - the overview card's feed. */
    recentActivity: AuditRow[];
    admin: ProjectAdmin;
    viewer: ProjectViewer;
    /** The tab the URL names, validated server-side (see useScreen). */
    screen: string;
}
