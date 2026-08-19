<?php

namespace App\Actions\Variables;

use App\Models\User;
use App\Models\Variable;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;

/**
 * Retires a variable across every environment at once.
 *
 * Soft delete only, and the values stay encrypted in place: a deleted variable
 * that also destroyed the only copy of a production secret would turn a misclick
 * into an outage. The audit entry counts the environments affected but, as
 * everywhere else, never touches the values themselves.
 */
class DeleteVariable
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(Variable $variable, User $actor): void
    {
        $project = $variable->project;

        $this->audit->record(
            'variable.deleted',
            subject: $variable,
            properties: [
                'key' => $variable->key,
                'sensitivity' => $variable->sensitivity->value,
                'environments' => $variable->values()->count(),
            ],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $actor,
        );

        $variable->delete();
    }
}
