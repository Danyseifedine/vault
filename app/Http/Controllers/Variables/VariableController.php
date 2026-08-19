<?php

namespace App\Http\Controllers\Variables;

use App\Actions\Projects\CreateGroup;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\StoreVariableRequest;
use App\Http\Requests\Variables\UpdateVariableRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Variable;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The variable DEFINITION - key, labels, grouping. Values live in
 * VariableValueController, because they are governed per environment.
 *
 * No response from here ever carries a value.
 */
class VariableController extends Controller
{
    public function store(
        StoreVariableRequest $request,
        Organization $organization,
        Project $project,
        CreateVariable $createVariable,
        CreateGroup $createGroup,
    ): RedirectResponse {
        $this->authorize('create', [Variable::class, $project]);

        $variable = $createVariable(
            $project,
            $request->user(),
            $request->string('key')->value(),
            $this->groupIdFor($request, $project, $createGroup),
            $request->input('description'),
            $request->sensitivity(),
            $request->changeSafety(),
        );

        return back()->with('success', "{$variable->key} created.");
    }

    public function update(
        UpdateVariableRequest $request,
        Organization $organization,
        Project $project,
        Variable $variable,
        AuditRecorder $audit,
        CreateGroup $createGroup,
    ): RedirectResponse {
        $this->authorize('update', $variable);

        $groupId = $this->groupIdFor($request, $project, $createGroup);

        if ($groupId !== null && ! $project->groups()->whereKey($groupId)->exists()) {
            throw ValidationException::withMessages([
                'group_id' => 'That group does not belong to this project.',
            ]);
        }

        $before = $variable->only(['key', 'sensitivity', 'change_safety', 'group_id']);

        $variable->fill([
            'key' => $request->string('key')->value(),
            'description' => $request->input('description'),
            'sensitivity' => $request->sensitivity(),
            'change_safety' => $request->changeSafety(),
            'group_id' => $groupId,
        ])->save();

        $audit->record(
            'variable.updated',
            subject: $variable,
            properties: ['from' => $before, 'to' => $variable->only(array_keys($before))],
            scope: AuditScope::make($project->organization_id, $project->id),
            causer: $request->user(),
        );

        return back()->with('success', "{$variable->key} updated.");
    }

    public function destroy(
        Organization $organization,
        Project $project,
        Variable $variable,
        DeleteVariable $deleteVariable,
    ): RedirectResponse {
        $this->authorize('delete', $variable);

        $deleteVariable($variable, request()->user());

        return back()->with('success', "{$variable->key} deleted.");
    }

    /**
     * Which group this variable is filed under.
     *
     * A NAME instead of an id means a group that does not exist yet. It is
     * created through CreateGroup - the one place that checks `groups.manage`
     * and writes the audit entry - so filing a variable never becomes a second,
     * quieter way to add one. An existing name is reused rather than refused:
     * the picker is a text box, and typing "Database" twice is not an error
     * worth losing a save over.
     */
    private function groupIdFor(
        StoreVariableRequest|UpdateVariableRequest $request,
        Project $project,
        CreateGroup $createGroup,
    ): ?int {
        $name = trim((string) $request->input('group_name'));

        if ($name === '') {
            return $request->input('group_id') === null ? null : $request->integer('group_id');
        }

        $existing = Group::query()
            ->where('project_id', $project->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        return $createGroup($project, $request->user(), $name)->id;
    }
}
