<?php

namespace App\Services\Dashboard;

use App\Models\Organization;
use App\Models\SharedGroup;
use App\Models\SharedSecret;
use App\Models\User;
use App\Services\Shared\SharedVaultGuard;

/**
 * The shared vault as the organization page needs it.
 *
 * Masked metadata only - name, type, group, who added it and when. The value
 * never travels; it is fetched once, through the reveal endpoint, with a PIN
 *.
 */
class SharedVaultData
{
    public function __construct(private SharedVaultGuard $guard) {}

    /** @return array<string, mixed> */
    public function for(Organization $organization, User $viewer): array
    {
        $canView = $this->guard->canView($viewer, $organization);

        return [
            'canView' => $canView,
            'canReveal' => $this->guard->canReveal($viewer, $organization),
            'canManage' => $this->guard->canManage($viewer, $organization),
            'groups' => $canView ? $this->groups($organization) : [],
            'items' => $canView ? $this->items($organization) : [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function groups(Organization $organization): array
    {
        return $organization->sharedGroups()
            ->withCount('secrets')
            ->get()
            ->map(fn (SharedGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'items' => $group->secrets_count,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function items(Organization $organization): array
    {
        return $organization->sharedSecrets()
            ->with('group')
            ->orderBy('name')
            ->get()
            ->map(fn (SharedSecret $secret) => [
                'id' => $secret->id,
                'name' => $secret->name,
                'type' => $secret->type->value,
                'description' => $secret->description,
                'groupId' => $secret->shared_group_id,
                'group' => $secret->group === null ? 'ungrouped' : $secret->group->name,
                'addedAt' => $secret->created_at?->diffForHumans(short: true),
            ])
            ->all();
    }
}
