<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The project audit screen, page by page.
 *
 * This screen used to read the newest 200 rows, drop the ones naming an
 * environment the viewer cannot see, and show 50 of what survived. Past 200
 * entries older history simply stopped existing, and no correct pager can be
 * built on a set filtered after the limit - so the environment rule moved into
 * SQL, where paging can be trusted.
 */
class ProjectAuditPaginationTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = Project::factory()->for($this->organization)->create(['slug' => 'lebify']);
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev', 'slug' => 'dev']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod', 'slug' => 'prod']);
    }

    private function record(string $event, ?string $environment = null, bool $failed = false): void
    {
        $recorder = app(AuditRecorder::class);
        $properties = ['key' => 'FAKE'] + ($environment === null ? [] : ['environment' => $environment]);
        $scope = AuditScope::make($this->organization->id, $this->project->id);

        $failed
            ? $recorder->failure($event, properties: $properties, scope: $scope, causer: $this->owner)
            : $recorder->record($event, properties: $properties, scope: $scope, causer: $this->owner);
    }

    /** @return array<string, mixed> */
    private function audit(array $query = [], ?User $viewer = null): array
    {
        $url = route('projects.show', [$this->organization, $this->project])
            .'?'.http_build_query($query + ['screen' => 'audit']);

        $response = $this->actingAs($viewer ?? $this->owner)->get($url);
        $response->assertOk();

        return $response->viewData('page')['props']['auditLog'];
    }

    public function test_the_first_page_carries_its_slice_and_the_totals(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->record('variable.revealed', 'dev');
        }

        $log = $this->audit(['perPage' => 10]);

        $this->assertCount(10, $log['rows']);
        $this->assertSame(1, $log['page']);
        $this->assertSame(30, $log['total']);
        $this->assertSame(3, $log['lastPage']);
    }

    public function test_the_second_page_is_a_different_slice(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->record("variable.revealed.{$i}", 'dev');
        }

        $first = $this->audit(['perPage' => 10]);
        $second = $this->audit(['perPage' => 10, 'page' => 2]);

        $this->assertNotEquals(
            collect($first['rows'])->pluck('action')->all(),
            collect($second['rows'])->pluck('action')->all(),
        );
    }

    /** The whole reason the environment rule had to move into SQL. */
    public function test_history_beyond_the_old_two_hundred_row_ceiling_is_reachable(): void
    {
        for ($i = 0; $i < 210; $i++) {
            $this->record('variable.revealed', 'dev');
        }

        $log = $this->audit(['perPage' => 100]);

        $this->assertSame(210, $log['total']);
        $this->assertSame(3, $log['lastPage']);
        $this->assertCount(10, $this->audit(['perPage' => 100, 'page' => 3])['rows']);
    }

    public function test_an_environment_the_viewer_cannot_see_is_excluded_on_every_page(): void
    {
        $this->record('variable.revealed', 'prod');

        for ($i = 0; $i < 20; $i++) {
            $this->record('variable.revealed', 'dev');
        }

        // Dev only: prod history is not theirs to read, on any page.
        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewAllActivity, $this->organization);
        $this->grant($member, Permission::ViewVariables, $this->dev);

        $log = $this->audit(['perPage' => 100], $member);

        $this->assertSame(20, $log['total']);
        $this->assertNotContains(
            'prod',
            collect($log['rows'])->pluck('path')->all(),
        );
    }

    public function test_project_level_entries_naming_no_environment_are_kept(): void
    {
        $this->record('project.updated');

        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewAllActivity, $this->organization);
        $this->grant($member, Permission::ViewVariables, $this->dev);

        $log = $this->audit([], $member);

        $this->assertSame(1, $log['total']);
    }

    public function test_the_failure_filter_runs_in_sql_across_every_page(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->record('variable.revealed', 'dev');
        }

        $this->record('pin.failed', 'dev', failed: true);

        $log = $this->audit(['filter' => 'fail', 'perPage' => 10]);

        $this->assertSame(1, $log['total']);
        $this->assertSame('fail', $log['rows'][0]['kind']);
    }

    public function test_a_page_beyond_the_end_is_empty_rather_than_an_error(): void
    {
        $this->record('variable.revealed', 'dev');

        $this->assertSame([], $this->audit(['page' => 999])['rows']);
    }

    public function test_per_page_is_bounded(): void
    {
        $this->record('variable.revealed', 'dev');

        $this->assertLessThanOrEqual(100, $this->audit(['perPage' => 100000])['perPage']);
    }

    public function test_another_persons_entries_still_need_the_grant(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->record('variable.revealed', 'dev');
        }

        // No audit.view-all: their own entries only.
        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewVariables, $this->dev);

        $this->assertSame(0, $this->audit([], $member)['total']);
    }
}
