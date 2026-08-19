<?php

namespace Tests\Feature\Projects;

use App\Actions\PersonalVault\CreatePersonalGroup;
use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\PersonalVault\UpdatePersonalItem;
use App\Actions\Projects\CreateEnvironment;
use App\Actions\Projects\UpdateEnvironment;
use App\Actions\Tags\AttachTags;
use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\UpdateTag;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The edit and delete paths that were missing: renaming an environment,
 * maintaining tags, and editing a personal item.
 */
class RemainingCrudTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $prod;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->creator;
        $this->project = Project::factory()->for($this->organization)->create();
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
    }

    private function member(): User
    {
        return $this->joinMember($this->organization);
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    // ── Environments ────────────────────────────────────────────────────────

    public function test_an_environment_can_be_renamed_and_its_slug_follows(): void
    {
        app(UpdateEnvironment::class)($this->prod, $this->owner, 'Production', 5);

        $environment = $this->prod->fresh();

        $this->assertSame('Production', $environment->name);
        $this->assertSame('production', $environment->slug);
        $this->assertSame(5, $environment->position);
        $this->assertContains('environment.updated', $this->events());
    }

    /** Renaming must not silently orphan the values stored against it. */
    public function test_renaming_an_environment_keeps_its_values(): void
    {
        $variable = Variable::factory()->for($this->project)->create(['key' => 'DATABASE_URL']);
        $variable->values()->create([
            'environment_id' => $this->prod->id,
            'value' => 'postgres://fake',
            'updated_by' => $this->owner->id,
        ]);

        app(UpdateEnvironment::class)($this->prod, $this->owner, 'Production');

        $this->assertSame('postgres://fake', $variable->fresh()->valueIn($this->prod->fresh())?->value);
    }

    public function test_two_environments_in_one_project_cannot_share_a_name(): void
    {
        app(CreateEnvironment::class)($this->project, $this->owner, 'dev');

        $this->expectException(ValidationException::class);

        app(UpdateEnvironment::class)($this->prod, $this->owner, 'dev');
    }

    public function test_a_plain_member_cannot_rename_an_environment(): void
    {
        $this->expectException(AuthorizationException::class);

        app(UpdateEnvironment::class)($this->prod, $this->member(), 'Production');
    }

    // ── Tags ────────────────────────────────────────────────────────────────

    public function test_a_tag_can_be_renamed(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');

        app(UpdateTag::class)($tag, $this->owner, 'deprecated');

        $this->assertSame('deprecated', (string) $tag->fresh()->name);
        $this->assertContains('tag.updated', $this->events());
    }

    public function test_a_rename_cannot_collide_within_the_same_scope(): void
    {
        app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
        $other = app(CreateTag::class)->forProject($this->project, $this->owner, 'rotate-soon');

        $this->expectException(ValidationException::class);

        app(UpdateTag::class)($other, $this->owner, 'legacy');
    }

    public function test_renaming_an_organization_wide_tag_takes_the_global_tag_grant(): void
    {
        $tag = app(CreateTag::class)->global($this->organization, $this->owner, 'company-wide');

        // Project-level tag authority is not enough for an org-wide label.
        $projectTagger = $this->member();
        $this->grant($projectTagger, Permission::CreateTags, $this->project);

        $this->expectException(AuthorizationException::class);

        app(UpdateTag::class)($tag, $projectTagger, 'renamed');
    }

    public function test_deleting_a_tag_removes_it_from_every_variable(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
        $variable = Variable::factory()->for($this->project)->create(['key' => 'API_URL']);

        app(AttachTags::class)($variable, $this->owner, [$tag->id]);
        $this->assertCount(1, $variable->fresh()->tags);

        app(DeleteTag::class)($tag, $this->owner);

        $this->assertCount(0, $variable->fresh()->tags);
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertContains('tag.deleted', $this->events());
    }

    public function test_a_plain_member_cannot_delete_a_tag(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');

        $this->expectException(AuthorizationException::class);

        app(DeleteTag::class)($tag, $this->member());
    }

    // ── Personal items ──────────────────────────────────────────────────────

    public function test_the_owner_renames_and_refiles_their_own_item(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake');
        $group = app(CreatePersonalGroup::class)($this->owner, 'Work');

        app(UpdatePersonalItem::class)($secret, $this->owner, 'GitHub token', $group->id, 'personal access token');

        $updated = $secret->fresh();

        $this->assertSame('GitHub token', $updated->name);
        $this->assertSame($group->id, $updated->personal_group_id);
        $this->assertContains('personal.updated', $this->events());
    }

    /** Editing metadata must never touch the secret itself. */
    public function test_editing_an_item_leaves_its_value_untouched(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'ghp_fake_123');

        app(UpdatePersonalItem::class)($secret, $this->owner, 'Renamed');

        $this->assertSame('ghp_fake_123', $secret->fresh()->value);
        $this->assertStringNotContainsString(
            'ghp_fake_123',
            DB::table('activity_log')->pluck('properties')->implode(' '),
        );
    }

    public function test_an_item_cannot_be_refiled_into_someone_elses_group(): void
    {
        $stranger = User::factory()->onboarded()->create();
        $theirs = app(CreatePersonalGroup::class)($stranger, 'Theirs');
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake');

        $this->expectException(ValidationException::class);

        app(UpdatePersonalItem::class)($secret, $this->owner, 'Token', $theirs->id);
    }

    public function test_nobody_else_can_edit_an_item(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake');

        $this->expectException(AuthorizationException::class);

        app(UpdatePersonalItem::class)($secret, User::factory()->onboarded()->create(), 'Hijacked');
    }
}
