<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The activity screen, page by page.
 *
 * The log is the one table that only grows, so it is the one screen that
 * cannot ship "the latest 50 and hope". Paging happens in SQL, and so does the
 * success/failure filter - filtering one page client-side would quietly hide
 * failures that live on page two.
 */
class ActivityPaginationTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
    }

    private function record(string $event, bool $failed = false): void
    {
        $recorder = app(AuditRecorder::class);
        $scope = AuditScope::make($this->organization->id);

        $failed
            ? $recorder->failure($event, properties: ['key' => 'FAKE'], scope: $scope, causer: $this->owner)
            : $recorder->record($event, properties: ['key' => 'FAKE'], scope: $scope, causer: $this->owner);
    }

    /** @return array<string, mixed> */
    private function activity(array $query = [], ?User $viewer = null): array
    {
        $response = $this->actingAs($viewer ?? $this->owner)
            ->get(route('organizations.show', $this->organization).'?'.http_build_query($query + ['screen' => 'activity']));

        $response->assertOk();

        return $response->viewData('page')['props']['auditLog'];
    }

    public function test_the_first_page_carries_its_own_slice_and_the_totals(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->record('variable.revealed');
        }

        $log = $this->activity(['perPage' => 10]);

        $this->assertCount(10, $log['rows']);
        $this->assertSame(1, $log['page']);
        $this->assertSame(10, $log['perPage']);
        // 30 recorded plus the entries CreateOrganization wrote itself.
        $this->assertGreaterThanOrEqual(30, $log['total']);
        $this->assertGreaterThan(1, $log['lastPage']);
    }

    public function test_the_second_page_is_a_different_slice(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->record("variable.revealed.{$i}");
        }

        $first = $this->activity(['perPage' => 10]);
        $second = $this->activity(['perPage' => 10, 'page' => 2]);

        $this->assertSame(2, $second['page']);
        $this->assertNotEquals(
            collect($first['rows'])->pluck('action')->all(),
            collect($second['rows'])->pluck('action')->all(),
        );
    }

    public function test_a_page_beyond_the_end_is_empty_rather_than_an_error(): void
    {
        $this->record('variable.revealed');

        $log = $this->activity(['page' => 999]);

        $this->assertSame([], $log['rows']);
    }

    public function test_a_page_below_one_is_treated_as_the_first(): void
    {
        $this->record('variable.revealed');

        $this->assertSame(1, $this->activity(['page' => 0])['page']);
        $this->assertSame(1, $this->activity(['page' => -5])['page']);
    }

    public function test_the_failure_filter_runs_in_sql_across_every_page(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->record('variable.revealed');
        }

        // One failure, deliberately buried behind a full page of successes.
        $this->record('pin.failed', failed: true);

        $log = $this->activity(['filter' => 'fail', 'perPage' => 10]);

        $this->assertSame(1, $log['total']);
        $this->assertCount(1, $log['rows']);
        $this->assertSame('fail', $log['rows'][0]['kind']);
    }

    public function test_the_success_filter_excludes_failures(): void
    {
        $this->record('variable.revealed');
        $this->record('pin.failed', failed: true);

        $log = $this->activity(['filter' => 'ok']);

        $this->assertNotContains('fail', collect($log['rows'])->pluck('kind')->all());
    }

    public function test_an_unknown_filter_falls_back_to_all(): void
    {
        $this->record('variable.revealed');
        $this->record('pin.failed', failed: true);

        $log = $this->activity(['filter' => 'nonsense']);

        $this->assertSame('all', $log['filter']);
        $this->assertGreaterThanOrEqual(2, $log['total']);
    }

    public function test_per_page_is_bounded_so_a_url_cannot_ask_for_everything(): void
    {
        $this->record('variable.revealed');

        $this->assertLessThanOrEqual(100, $this->activity(['perPage' => 100000])['perPage']);
        $this->assertGreaterThanOrEqual(1, $this->activity(['perPage' => 0])['perPage']);
    }

    public function test_paging_never_widens_what_a_viewer_may_read(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->record('variable.revealed');
        }

        // No audit.view-all: their own entries only, on every page.
        $member = $this->joinMember($this->organization);
        $this->grant($member, Permission::ViewSharedVault, $this->organization);

        $log = $this->activity(['perPage' => 10], $member);

        $this->assertSame(0, $log['total']);
        $this->assertSame([], $log['rows']);
    }
}
