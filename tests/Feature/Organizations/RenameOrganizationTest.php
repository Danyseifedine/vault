<?php

namespace Tests\Feature\Organizations;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

class RenameOrganizationTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    public function test_the_creator_can_rename_their_organization(): void
    {
        [$creator, $organization] = $this->created();

        $this->actingAs($creator)
            ->patch(route('organizations.update', $organization), ['name' => 'Lebify Labs'])
            ->assertRedirect();

        $this->assertSame('Lebify Labs', $organization->refresh()->name);
    }

    /** The permission is the whole story - a plain member granted just organization.update may rename it. */
    public function test_any_holder_of_organization_update_can_rename_it(): void
    {
        [, $organization] = $this->created();
        $member = $this->joinMember($organization);
        $this->grant($member, Permission::UpdateOrganization, $organization);

        $this->actingAs($member)
            ->patch(route('organizations.update', $organization), ['name' => 'Lebify Labs'])
            ->assertRedirect();

        $this->assertSame('Lebify Labs', $organization->refresh()->name);
    }

    /**
     * The slug follows the name, exactly as it does for projects, environments,
     * groups. Links already pasted somewhere stop resolving - that is
     * the accepted cost of a URL that never lies about what it points at.
     */
    public function test_the_url_follows_the_new_name(): void
    {
        [$creator, $organization] = $this->created();

        $this->actingAs($creator)->patch(route('organizations.update', $organization), ['name' => 'Something Else']);

        $this->assertSame('something-else', $organization->refresh()->slug);
    }

    /** Near-power does not leak: every other administrative permission is not this one. */
    public function test_a_member_holding_other_administrative_permissions_cannot_rename_it(): void
    {
        [, $organization] = $this->created();
        $almost = $this->joinMember($organization);
        $this->grant($almost, [
            Permission::UpdateSettings,
            Permission::ManageMembers,
        ], $organization);

        $this->actingAs($almost)
            ->patch(route('organizations.update', $organization), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $organization->refresh()->name);
    }

    public function test_a_stranger_cannot_rename_it(): void
    {
        [, $organization] = $this->created();

        $this->actingAs(User::factory()->onboarded()->create())
            ->patch(route('organizations.update', $organization), ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        [, $organization] = $this->created();

        $this->patch(route('organizations.update', $organization), ['name' => 'Hijacked'])
            ->assertRedirect(route('login'));
    }

    public function test_a_blank_name_is_refused(): void
    {
        [$creator, $organization] = $this->created();

        $this->actingAs($creator)
            ->patch(route('organizations.update', $organization), ['name' => '   '])
            ->assertSessionHasErrors('name');
    }

    public function test_an_overlong_name_is_refused(): void
    {
        [$creator, $organization] = $this->created();

        $this->actingAs($creator)
            ->patch(route('organizations.update', $organization), ['name' => str_repeat('a', 121)])
            ->assertSessionHasErrors('name');
    }

    public function test_the_rename_is_audited(): void
    {
        [$creator, $organization] = $this->created();
        $was = $organization->name;

        $this->actingAs($creator)->patch(route('organizations.update', $organization), ['name' => 'Renamed']);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'organization.renamed',
            'organization_id' => $organization->id,
        ]);

        $this->assertNotSame($was, $organization->refresh()->name);
    }

    public function test_a_refused_rename_is_recorded(): void
    {
        [, $organization] = $this->created();
        $almost = $this->joinMember($organization);
        $this->grant($almost, Permission::UpdateSettings, $organization);

        $this->actingAs($almost)->patch(route('organizations.update', $organization), ['name' => 'Hijacked']);

        $this->assertDatabaseHas('activity_log', ['event' => 'organization.rename-denied']);
    }

    /** @return array{0: User, 1: Organization} */
    private function created(): array
    {
        $creator = User::factory()->onboarded()->create();

        return [$creator, Organization::factory()->create(['created_by' => $creator->id])->refresh()];
    }
}
