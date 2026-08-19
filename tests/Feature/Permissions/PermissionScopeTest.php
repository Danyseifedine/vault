<?php

namespace Tests\Feature\Permissions;

use App\Enums\GrantScope;
use App\Enums\Permission;
use Tests\TestCase;

/**
 * The permission catalogue is the contract the whole grants model stands on:
 * every case belongs to exactly one native scope, and a grant row may only
 * carry a permission at its native scope or wider.
 */
class PermissionScopeTest extends TestCase
{
    /** @var array<int, Permission> */
    private const ENVIRONMENT_NATIVE = [
        Permission::ViewVariables,
        Permission::RevealValues,
        Permission::ExportEnv,
        Permission::CreateVariables,
        Permission::UpdateVariables,
        Permission::RollbackVariables,
        Permission::ImportEnv,
        Permission::DeleteVariables,
    ];

    /** @var array<int, Permission> */
    private const PROJECT_NATIVE = [
        Permission::UpdateSettings,
        Permission::ManageEnvironments,
        Permission::ManageGroups,
        Permission::CreateTags,
    ];

    /** @var array<int, Permission> */
    private const ORGANIZATION_NATIVE = [
        Permission::UpdateOrganization,
        Permission::DeleteOrganization,
        Permission::CreateProjects,
        Permission::InviteMembers,
        Permission::ManageMembers,
        Permission::ViewAllActivity,
        Permission::WipeActivity,
        Permission::ViewSharedVault,
        Permission::RevealSharedVault,
        Permission::ManageSharedVault,
        Permission::ManagePins,
        Permission::CreateGlobalTags,
    ];

    public function test_every_permission_belongs_to_exactly_one_native_scope(): void
    {
        foreach (self::ENVIRONMENT_NATIVE as $permission) {
            $this->assertSame(GrantScope::Environment, $permission->scope(), $permission->value);
        }

        foreach (self::PROJECT_NATIVE as $permission) {
            $this->assertSame(GrantScope::Project, $permission->scope(), $permission->value);
        }

        foreach (self::ORGANIZATION_NATIVE as $permission) {
            $this->assertSame(GrantScope::Organization, $permission->scope(), $permission->value);
        }

        // The three lists together are the whole enum - nothing unclassified.
        $this->assertCount(
            count(Permission::cases()),
            [...self::ENVIRONMENT_NATIVE, ...self::PROJECT_NATIVE, ...self::ORGANIZATION_NATIVE],
        );
    }

    public function test_environment_actions_keep_the_matrix_order(): void
    {
        // The dashboard matrix and permissionKeys both index into this order.
        $this->assertSame(self::ENVIRONMENT_NATIVE, Permission::environmentActions());
    }

    public function test_administrative_means_anything_above_an_environment(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertSame(
                $permission->scope() !== GrantScope::Environment,
                $permission->isAdministrative(),
                $permission->value,
            );
        }
    }

    public function test_every_permission_has_a_label(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotSame('', $permission->label(), $permission->value);
        }
    }

    /**
     * An org row may carry anything; a project row anything project-down; an
     * environment row only environment actions. Wider never rides narrower.
     */
    public function test_the_covered_by_truth_table(): void
    {
        $expected = [
            // [permission native scope, row scope, allowed?]
            [GrantScope::Organization, GrantScope::Organization, true],
            [GrantScope::Organization, GrantScope::Project, false],
            [GrantScope::Organization, GrantScope::Environment, false],
            [GrantScope::Project, GrantScope::Organization, true],
            [GrantScope::Project, GrantScope::Project, true],
            [GrantScope::Project, GrantScope::Environment, false],
            [GrantScope::Environment, GrantScope::Organization, true],
            [GrantScope::Environment, GrantScope::Project, true],
            [GrantScope::Environment, GrantScope::Environment, true],
        ];

        foreach ($expected as [$native, $row, $allowed]) {
            $this->assertSame(
                $allowed,
                $native->coveredBy($row),
                "{$native->value} on a {$row->value} row",
            );
        }
    }
}
