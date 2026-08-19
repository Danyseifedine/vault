<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Tags\CreateTag;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The page must know what the server would refuse, or the only way to learn is
 * to click and land on an error page. These pin the flags the screens lock on.
 */
class LockedActionsTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private Environment $prod;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
        $this->creator = $this->organization->creator;
    }

    private function seedVariable(string $key, Environment ...$environments): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->creator, $key);

        foreach ($environments as $environment) {
            app(SetVariableValue::class)($variable, $environment, $this->creator, 'fake-value');
        }
    }

    public function test_a_viewer_with_everything_is_told_they_may_do_everything(): void
    {
        $this->seedVariable('DATABASE_URL', $this->dev, $this->prod);

        $this->actingAs($this->creator)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                ->where('viewer.environments.dev.create', true)
                ->where('viewer.environments.dev.update', true)
                ->where('viewer.environments.dev.reveal', true)
                ->where('viewer.environments.dev.import', true)
                ->where('viewer.environments.dev.export', true)
                ->where('viewer.canTag', true)
                ->where('variables.0.canEdit', true)
                ->where('variables.0.canDelete', true));
    }

    public function test_a_read_only_viewer_is_told_every_write_is_shut(): void
    {
        $this->seedVariable('DATABASE_URL', $this->dev);

        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->dev);

        $this->actingAs($reader)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                ->where('viewer.environments.dev.reveal', true)
                ->where('viewer.environments.dev.export', true)
                ->where('viewer.environments.dev.create', false)
                ->where('viewer.environments.dev.update', false)
                ->where('viewer.environments.dev.rollback', false)
                ->where('viewer.environments.dev.import', false)
                ->where('variables.0.canEdit', false)
                ->where('variables.0.canDelete', false));
    }

    /**
     * The subtle one: writing in dev does NOT let you edit a variable that
     * also lives in prod, and the row must say so before it is clicked.
     */
    public function test_a_dev_writer_cannot_edit_a_variable_that_also_lives_in_prod(): void
    {
        $this->seedVariable('DEV_ONLY', $this->dev);
        $this->seedVariable('EVERYWHERE', $this->dev, $this->prod);

        $writer = $this->joinMember($this->organization);
        $this->grantWriter($writer, $this->dev);

        $this->actingAs($writer)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                // Alphabetical: DEV_ONLY first, EVERYWHERE second.
                ->where('variables.0.key', 'DEV_ONLY')
                ->where('variables.0.canEdit', true)
                ->where('variables.1.key', 'EVERYWHERE')
                ->where('variables.1.canEdit', false)
                ->where('variables.1.canDelete', false));
    }

    public function test_the_environment_map_only_carries_environments_the_viewer_sees(): void
    {
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->dev);

        $this->actingAs($reader)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                ->has('viewer.environments.dev')
                ->missing('viewer.environments.prod'));
    }

    public function test_the_tags_screen_says_which_tags_this_viewer_may_change(): void
    {
        $labeller = $this->joinMember($this->organization);
        $this->grant($labeller, Permission::CreateTags, $this->project);

        app(CreateTag::class)->forProject($this->project, $this->creator, 'rotate-soon');
        app(CreateTag::class)->global($this->organization, $this->creator, 'org-wide');

        $response = $this->actingAs($labeller)
            ->get(route('organizations.show', $this->organization));

        $props = $response->viewData('page')['props'];
        $tags = collect($props['tags']);

        $this->assertTrue($props['organization']['canCreateTags']);
        $this->assertFalse($props['organization']['canCreateGlobalTags']);

        // The project tag is theirs to rename; the org-wide one is not.
        $this->assertTrue($tags->firstWhere('name', 'rotate-soon')['canManage']);
        $this->assertFalse($tags->firstWhere('name', 'org-wide')['canManage']);
    }

    public function test_a_member_with_no_tag_rights_is_offered_nothing_to_create(): void
    {
        $member = $this->joinMember($this->organization);

        $this->actingAs($member)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('organization.canCreateTags', false)
                ->where('scopes', []));
    }
}
