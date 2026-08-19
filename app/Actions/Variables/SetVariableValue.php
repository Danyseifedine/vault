<?php

namespace App\Actions\Variables;

use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableValue;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;

/**
 * Writes a value into one environment, keeping the previous one in history.
 *
 * Every change bumps the version and snapshots what came before, so "what was
 * this yesterday, and who changed it?" always has an answer.
 */
class SetVariableValue
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(
        Variable $variable,
        Environment $environment,
        User $author,
        #[\SensitiveParameter] string $value,
        string $updateEvent = 'variable.value-updated',
    ): VariableValue {
        return DB::transaction(function () use ($variable, $environment, $author, $value, $updateEvent): VariableValue {
            $existing = $variable->valueIn($environment);

            if ($existing === null) {
                $created = VariableValue::create([
                    'variable_id' => $variable->id,
                    'environment_id' => $environment->id,
                    'value' => $value,
                    'version' => 1,
                    'updated_by' => $author->id,
                ]);

                $created->versions()->create([
                    'version' => 1,
                    'value' => $value,
                    'changed_by' => $author->id,
                    'created_at' => now(),
                ]);

                $this->auditChange('variable.value-created', $variable, $environment, $author, null, $value, 1);

                return $created;
            }

            $previousValue = $existing->value;
            $nextVersion = $existing->version + 1;

            $existing->forceFill([
                'value' => $value,
                'version' => $nextVersion,
                'updated_by' => $author->id,
            ])->save();

            $existing->versions()->create([
                'version' => $nextVersion,
                'value' => $value,
                'changed_by' => $author->id,
                'created_at' => now(),
            ]);

            $this->auditChange($updateEvent, $variable, $environment, $author, $previousValue, $value, $nextVersion);

            return $existing;
        });
    }

    private function auditChange(
        string $event,
        Variable $variable,
        Environment $environment,
        User $author,
        #[\SensitiveParameter] ?string $old,
        #[\SensitiveParameter] string $new,
        int $version,
    ): void {
        $this->audit->record(
            $event,
            subject: $variable,
            properties: [
                'key' => $variable->key,
                'environment' => $environment->slug,
                'version' => $version,
            ],
            // Old and new values are kept, but encrypted: the audit log must
            // never become the place secrets leak from.
            secrets: ['old' => $old, 'new' => $new],
            scope: AuditScope::make($variable->project->organization_id, $variable->project_id),
            causer: $author,
        );
    }
}
