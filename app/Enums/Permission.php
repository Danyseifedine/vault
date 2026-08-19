<?php

namespace App\Enums;

/**
 * Every distinct thing a person can do. Named `action.resource` so grants read
 * like sentences in the audit log.
 *
 * Each permission is native to ONE scope - the narrowest place it means
 * anything. A grant row may carry it at that scope or wider (GrantScope), and
 * a wider row simply covers every narrower place, including ones created later.
 */
enum Permission: string
{
    // Environment-native: act on variables inside one environment.
    case ViewVariables = 'variables.view';
    case RevealValues = 'variables.reveal';
    case ExportEnv = 'variables.export';
    case CreateVariables = 'variables.create';
    case UpdateVariables = 'variables.update';
    case RollbackVariables = 'variables.rollback';
    case ImportEnv = 'variables.import';
    case DeleteVariables = 'variables.delete';

    // Project-native: change the shape of one project.
    case UpdateSettings = 'settings.update';
    case ManageEnvironments = 'environments.manage';
    case ManageGroups = 'groups.manage';
    case CreateTags = 'tags.create';

    // Organization-native: act on the organization itself.
    case UpdateOrganization = 'organization.update';
    case DeleteOrganization = 'organization.delete';
    case CreateProjects = 'projects.create';
    case InviteMembers = 'members.invite';
    case ManageMembers = 'members.manage';
    case ViewAllActivity = 'audit.view-all';
    case WipeActivity = 'audit.wipe';
    case ViewSharedVault = 'shared.view';
    case RevealSharedVault = 'shared.reveal';
    case ManageSharedVault = 'shared.manage';
    case ManagePins = 'pins.manage';
    case CreateGlobalTags = 'tags.create-global';

    public function scope(): GrantScope
    {
        return match ($this) {
            self::ViewVariables,
            self::RevealValues,
            self::ExportEnv,
            self::CreateVariables,
            self::UpdateVariables,
            self::RollbackVariables,
            self::ImportEnv,
            self::DeleteVariables => GrantScope::Environment,

            self::UpdateSettings,
            self::ManageEnvironments,
            self::ManageGroups,
            self::CreateTags => GrantScope::Project,

            self::UpdateOrganization,
            self::DeleteOrganization,
            self::CreateProjects,
            self::InviteMembers,
            self::ManageMembers,
            self::ViewAllActivity,
            self::WipeActivity,
            self::ViewSharedVault,
            self::RevealSharedVault,
            self::ManageSharedVault,
            self::ManagePins,
            self::CreateGlobalTags => GrantScope::Organization,
        };
    }

    /**
     * The environment actions, in the order a permissions matrix reads best:
     * see it, open it, take it out, then change it.
     *
     * @return array<int, self>
     */
    public static function environmentActions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission) => $permission->scope() === GrantScope::Environment,
        ));
    }

    /** Anything above an environment: powers over shape, people and keys. */
    public function isAdministrative(): bool
    {
        return $this->scope() !== GrantScope::Environment;
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewVariables => 'View variables',
            self::RevealValues => 'Reveal values',
            self::ExportEnv => 'Export .env',
            self::CreateVariables => 'Create variables',
            self::UpdateVariables => 'Update values',
            self::RollbackVariables => 'Roll back values',
            self::ImportEnv => 'Import .env',
            self::DeleteVariables => 'Delete variables',
            self::UpdateSettings => 'Change project settings',
            self::ManageEnvironments => 'Manage environments',
            self::ManageGroups => 'Manage groups',
            self::CreateTags => 'Create tags',
            self::UpdateOrganization => 'Rename the organization',
            self::DeleteOrganization => 'Delete the organization',
            self::CreateProjects => 'Create projects',
            self::InviteMembers => 'Invite members',
            self::ManageMembers => 'Manage member access',
            self::ViewAllActivity => 'Read everyone\'s activity',
            self::WipeActivity => 'Wipe the activity log',
            self::ViewSharedVault => 'See the shared vault',
            self::RevealSharedVault => 'Reveal shared vault items',
            self::ManageSharedVault => 'Add and remove shared vault items',
            self::ManagePins => 'Manage reveal PINs',
            self::CreateGlobalTags => 'Create organization-wide tags',
        };
    }
}
