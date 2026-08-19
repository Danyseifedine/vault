<?php

namespace App\Actions\Variables;

use App\Enums\ChangeSafety;
use App\Enums\Sensitivity;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateVariable
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(
        Project $project,
        User $author,
        string $key,
        ?int $groupId = null,
        ?string $description = null,
        Sensitivity $sensitivity = Sensitivity::Sensitive,
        ChangeSafety $changeSafety = ChangeSafety::Safe,
    ): Variable {
        $this->guardProjectOwnsGroup($project, $groupId);

        return DB::transaction(function () use ($project, $author, $key, $groupId, $description, $sensitivity, $changeSafety): Variable {
            $variable = Variable::create([
                'project_id' => $project->id,
                'group_id' => $groupId,
                'key' => $key,
                'description' => $description,
                'sensitivity' => $sensitivity,
                'change_safety' => $changeSafety,
                'created_by' => $author->id,
            ]);

            $this->audit->record(
                'variable.created',
                subject: $variable,
                properties: ['key' => $key, 'sensitivity' => $sensitivity->value],
                scope: AuditScope::make($project->organization_id, $project->id),
                causer: $author,
            );

            return $variable;
        });
    }

    /**
     * A variable may only be filed under its own project's group. A foreign id
     * is refused as invalid input, exactly like PersonalGuard::requireOwnGroupId
     * does for its equivalent.
     */
    private function guardProjectOwnsGroup(Project $project, ?int $groupId): void
    {
        if ($groupId !== null && ! $project->groups()->whereKey($groupId)->exists()) {
            throw ValidationException::withMessages([
                'group_id' => 'That group does not belong to this project.',
            ]);
        }
    }
}
