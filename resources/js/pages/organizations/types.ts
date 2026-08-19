import type { AuditKind, AuditRow } from '@/components/vault';
import type { GrantRow } from '@/lib/access';

// Prop types for the organization dashboard - mirrors the eventual backend DTOs.
// No sample data lives here; pages render real Inertia props (empty until the
// backend exists).

export interface OrgProject {
    name: string;
    slug: string;
    initials: string;
    envs: number;
    vars: number;
    members: number;
    lastActivity: string | null;
    missing: number;
    mix: [number, number, number]; // critical / sensitive / public counts
}

export interface OrgActivityEntry {
    actor: string;
    initials: string;
    action: string;
    path: string;
    when: string;
    kind: AuditKind;
}

export interface OrgPin {
    id: number;
    label: string | null;
    status: 'active' | 'blocked';
}

export interface OrgMember {
    id: number;
    name: string;
    email: string;
    initials: string;
    scope: string;
    lastActive: string | null;
    /** Raw grant rows - filled only when the viewer holds members.manage. */
    grants: GrantRow[];
    pinSet: boolean;
    pins: OrgPin[];
}

export interface OrgTag {
    id: number;
    name: string;
    scope: 'organization' | 'project' | 'environment';
    /** Whether this viewer may rename or delete it - the rule TagAuthority uses. */
    canManage: boolean;
}

export interface OrgInvite {
    id: number;
    email: string;
    /** How many grants ride on the invite - the details live in the rows. */
    grants: number;
    expiresLabel: string;
}

export interface ScopeEnvironment {
    id: number;
    slug: string;
    name: string;
}

/** A project and its environments - what a grant row points at. */
export interface OrgScope {
    id: number;
    slug: string;
    name: string;
    /** Whether the viewer may create tags in this project. */
    canCreateTags: boolean;
    environments: ScopeEnvironment[];
}

/** One credential the whole organization shares - metadata only, never a value. */
export interface SharedItem {
    id: number;
    name: string;
    type: 'secret' | 'file';
    description: string | null;
    groupId: number | null;
    group: string;
    addedAt: string | null;
}

export interface SharedGroup {
    id: number;
    name: string;
    items: number;
}

export interface SharedVault {
    canView: boolean;
    canReveal: boolean;
    canManage: boolean;
    groups: SharedGroup[];
    /** Empty unless the viewer holds shared.view - the server decides. */
    items: SharedItem[];
}

/**
 * One page of the activity log. The log is the only unbounded table in the
 * product, so this screen is paged and filtered in SQL rather than shipped
 * whole - see AuditFeed::auditRows.
 */
export interface ActivityPage {
    rows: AuditRow[];
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
    filter: string;
    /** Every visible failure in the org, not just this page - the badge. */
    failures: number;
}

export interface OrgHealthStat {
    label: string;
    value: string;
    sub: string;
    tone: 'fg' | 'crit' | 'sens';
}

export interface OrganizationSummary {
    slug: string;
    name: string;
    canInvite: boolean;
    canCreateProjects: boolean;
    canManageMembers: boolean;
    /** Owner-only - narrower than every other permission here. */
    canManagePins: boolean;
    /** `audit.wipe` - the one power here that destroys rather than reads. */
    canWipeActivity: boolean;
    canCreateGlobalTags: boolean;
    /** True when there is at least one scope they may create a tag in. */
    canCreateTags: boolean;
}

export interface OrganizationPageProps {
    organization: OrganizationSummary;
    projects: OrgProject[];
    scopes: OrgScope[];
    tags: OrgTag[];
    activity: OrgActivityEntry[];
    activitySeries: { day: string; ok: number; fail: number }[];
    members: OrgMember[];
    invites: OrgInvite[];
    shared: SharedVault;
    auditLog: ActivityPage;
    health: OrgHealthStat[];
    /** The tab the URL names, validated server-side (see useScreen). */
    screen: string;
}
