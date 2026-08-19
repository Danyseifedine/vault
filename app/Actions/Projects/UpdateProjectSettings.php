<?php

namespace App\Actions\Projects;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Services\Access\ProjectChangeGuard;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Validation\ValidationException;

/**
 * Tunes a project's security posture.
 *
 * `audit_views` only ever governs reads. Creating, changing and deleting are
 * audited unconditionally - there is deliberately no setting that can stop it,
 * and this action records its own change even while switching view auditing off.
 */
class UpdateProjectSettings
{
    private const LIMITS = [
        'pin_max_attempts' => [1, 20],
        'pin_lockout_minutes' => [1, 1440],
    ];

    public function __construct(
        private ProjectChangeGuard $guard,
        private AuditRecorder $audit,
    ) {}

    /** @param  array{audit_views?: bool, pin_max_attempts?: int, pin_lockout_minutes?: int}  $changes */
    public function __invoke(Project $project, User $actor, array $changes): ProjectSettings
    {
        $this->guard->authorize($actor, $project, Permission::UpdateSettings, 'project-settings.update-denied');

        foreach (self::LIMITS as $field => [$min, $max]) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $value = (int) $changes[$field];

            if ($value < $min || $value > $max) {
                throw ValidationException::withMessages([
                    $field => "That value must be between {$min} and {$max}.",
                ]);
            }

            $changes[$field] = $value;
        }

        $settings = $project->settings()->firstOrCreate([]);
        $before = $settings->only(array_keys($changes));

        $settings->fill($changes)->save();

        $this->audit->record(
            'project-settings.updated',
            subject: $project,
            properties: ['from' => $before, 'to' => $changes],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        return $settings;
    }
}
