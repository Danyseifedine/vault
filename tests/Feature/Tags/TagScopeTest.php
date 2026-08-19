<?php

namespace Tests\Feature\Tags;

use App\Actions\Tags\AttachTags;
use App\Actions\Tags\CreateTag;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * A tag is a label with a reach. An environment tag must not wander onto a
 * variable that does not exist in that environment, and a project tag must not
 * escape its project - otherwise "prod-only" stops meaning anything.
 */
class TagScopeTest extends TestCase
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

    private function variable(string $key = 'DATABASE_URL'): Variable
    {
        return Variable::factory()->for($this->project)->create(['key' => $key]);
    }

    private function valueInProd(Variable $variable): void
    {
        $variable->values()->create([
            'environment_id' => $this->prod->id,
            'value' => 'fake-value',
            'updated_by' => $this->owner->id,
        ]);
    }

    // ── Creating ────────────────────────────────────────────────────────────

    public function test_an_owner_creates_an_organization_wide_tag(): void
    {
        $tag = app(CreateTag::class)->global($this->organization, $this->owner, 'rotate-soon');

        $this->assertSame($this->organization->id, $tag->organization_id);
        $this->assertNull($tag->project_id);
        $this->assertNull($tag->environment_id);
    }

    public function test_a_project_tag_grant_creates_project_tags_but_not_global_ones(): void
    {
        $tagger = $this->member();
        $this->grant($tagger, Permission::CreateTags, $this->project);

        $tag = app(CreateTag::class)->forProject($this->project, $tagger, 'legacy');
        $this->assertSame($this->project->id, $tag->project_id);

        $this->expectException(AuthorizationException::class);
        app(CreateTag::class)->global($this->organization, $tagger, 'company-wide');
    }

    public function test_a_plain_member_cannot_create_tags_and_the_attempt_is_audited(): void
    {
        $member = $this->member();

        try {
            app(CreateTag::class)->forProject($this->project, $member, 'nope');
            $this->fail('A plain member created a tag.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertContains('tag.create-denied', DB::table('activity_log')->pluck('event')->all());
    }

    public function test_creating_a_tag_is_audited(): void
    {
        app(CreateTag::class)->forEnvironment($this->prod, $this->owner, 'prod-only');

        $this->assertContains('tag.created', DB::table('activity_log')->pluck('event')->all());
    }

    public function test_the_same_name_may_exist_in_sibling_scopes(): void
    {
        $otherProject = Project::factory()->for($this->organization)->create();

        $here = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
        $there = app(CreateTag::class)->forProject($otherProject, $this->owner, 'legacy');

        $this->assertNotSame($here->id, $there->id);
    }

    public function test_the_same_name_twice_in_one_scope_is_rejected(): void
    {
        app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');

        $this->expectException(ValidationException::class);

        app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
    }

    // ── Containment ─────────────────────────────────────────────────────────

    public function test_a_global_tag_reaches_every_variable_in_its_organization(): void
    {
        $tag = app(CreateTag::class)->global($this->organization, $this->owner, 'rotate-soon');
        $variable = $this->variable();

        app(AttachTags::class)($variable, $this->owner, [$tag->id]);

        $this->assertSame(['rotate-soon'], $variable->fresh()->tags->pluck('name')->all());
    }

    public function test_a_global_tag_from_another_organization_is_refused(): void
    {
        $other = Organization::factory()->create();
        $foreign = app(CreateTag::class)->global($other, $other->creator, 'theirs');

        $this->expectException(ValidationException::class);

        app(AttachTags::class)($this->variable(), $this->owner, [$foreign->id]);
    }

    public function test_a_project_tag_reaches_variables_in_its_own_project_only(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');

        $otherProject = Project::factory()->for($this->organization)->create();
        $elsewhere = Variable::factory()->for($otherProject)->create(['key' => 'OTHER']);

        $this->expectException(ValidationException::class);

        app(AttachTags::class)($elsewhere, $this->owner, [$tag->id]);
    }

    /**
     * The point of an environment tag: it may only land on a variable that
     * actually exists in that environment.
     */
    public function test_an_environment_tag_needs_the_variable_to_exist_there(): void
    {
        $tag = app(CreateTag::class)->forEnvironment($this->prod, $this->owner, 'prod-only');
        $variable = $this->variable();

        try {
            app(AttachTags::class)($variable, $this->owner, [$tag->id]);
            $this->fail('An environment tag was attached to a variable with no value there.');
        } catch (ValidationException) {
            // expected
        }

        $this->valueInProd($variable);

        app(AttachTags::class)($variable, $this->owner, [$tag->id]);

        $this->assertSame(['prod-only'], $variable->fresh()->tags->pluck('name')->all());
    }

    public function test_attaching_replaces_the_previous_set_and_is_audited(): void
    {
        $first = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
        $second = app(CreateTag::class)->forProject($this->project, $this->owner, 'rotate-soon');
        $variable = $this->variable();

        $attach = app(AttachTags::class);
        $attach($variable, $this->owner, [$first->id]);
        $attach($variable, $this->owner, [$second->id]);

        $this->assertSame(['rotate-soon'], $variable->fresh()->tags->pluck('name')->all());
        $this->assertContains('variable.tags-changed', DB::table('activity_log')->pluck('event')->all());
    }

    public function test_an_empty_list_clears_every_tag(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
        $variable = $this->variable();

        $attach = app(AttachTags::class);
        $attach($variable, $this->owner, [$tag->id]);
        $attach($variable, $this->owner, []);

        $this->assertCount(0, $variable->fresh()->tags);
    }

    public function test_a_member_without_write_access_cannot_change_tags(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');
        $stranger = $this->member();

        $this->expectException(AuthorizationException::class);

        app(AttachTags::class)($this->variable(), $stranger, [$tag->id]);
    }

    public function test_scopes_are_readable_back_off_the_model(): void
    {
        $global = app(CreateTag::class)->global($this->organization, $this->owner, 'g');
        $project = app(CreateTag::class)->forProject($this->project, $this->owner, 'p');
        $environment = app(CreateTag::class)->forEnvironment($this->prod, $this->owner, 'e');

        $this->assertTrue($global->isGlobal());
        $this->assertFalse($project->isGlobal());
        $this->assertTrue($environment->isEnvironmentScoped());
        $this->assertFalse($project->isEnvironmentScoped());
    }

    public function test_available_tags_for_a_project_include_its_own_and_the_global_ones(): void
    {
        app(CreateTag::class)->global($this->organization, $this->owner, 'everywhere');
        app(CreateTag::class)->forProject($this->project, $this->owner, 'here');
        app(CreateTag::class)->forEnvironment($this->prod, $this->owner, 'prod-only');

        $otherProject = Project::factory()->for($this->organization)->create();
        app(CreateTag::class)->forProject($otherProject, $this->owner, 'not-here');

        $names = Tag::availableFor($this->project)->pluck('name')->sort()->values()->all();

        $this->assertSame(['everywhere', 'here', 'prod-only'], $names);
    }
}
