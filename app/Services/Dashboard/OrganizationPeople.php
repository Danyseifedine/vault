<?php

namespace App\Services\Dashboard;

use App\Models\Grant;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Pin;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The people half of the organization dashboard: joined members with their
 * reach and PINs, and the invitations still waiting to be accepted.
 */
class OrganizationPeople
{
    use FormatsInitials;

    /**
     * PIN details ride along only for pins.manage holders, and raw grant rows
     * only for members.manage holders - each maps a slice of the security
     * posture that is nobody else's to read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(Organization $organization, bool $withPins = false, bool $withGrants = false): array
    {
        $grantsByUser = $this->grantsByUser($organization);

        return $organization->members()
            ->wherePivotNotNull('joined_at')
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->displayName(),
                'email' => $member->email,
                'initials' => $this->initials($member->displayName()),
                'scope' => $this->scopeSummary($grantsByUser->get($member->id, collect())),
                'lastActive' => null,
                'grants' => $withGrants
                    ? $grantsByUser->get($member->id, collect())
                        ->map(fn (Grant $grant) => [
                            'permission' => $grant->permission->value,
                            'project_id' => $grant->project_id,
                            'environment_id' => $grant->environment_id,
                        ])
                        ->values()
                        ->all()
                    : [],
                'pinSet' => $withPins
                    && $member->pins()->active()->where('organization_id', $organization->id)->exists(),
                'pins' => $withPins
                    ? $member->pins()
                        ->where('organization_id', $organization->id)
                        ->get()
                        ->map(fn (Pin $pin) => [
                            'id' => $pin->id,
                            'label' => $pin->label,
                            'status' => $pin->status->value,
                        ])
                        ->all()
                    : [],
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function invites(Organization $organization): array
    {
        $grantsByUser = $this->grantsByUser($organization);

        return Invitation::query()
            ->pending()
            ->where('organization_id', $organization->id)
            ->with('user')
            ->get()
            ->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->user->email,
                'grants' => $grantsByUser->get($invitation->user_id, collect())->count(),
                'expiresLabel' => 'expires '.$invitation->expires_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Everyone's grants in one query - the summaries would otherwise run one
     * per person.
     *
     * @return Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, Grant>>
     */
    private function grantsByUser(Organization $organization): Collection
    {
        return Grant::query()
            ->where('organization_id', $organization->id)
            ->with(['project:id,name', 'environment:id,slug'])
            ->get()
            ->groupBy('user_id')
            ->toBase();
    }

    /** @param  Collection<int, Grant>  $grants */
    private function scopeSummary(Collection $grants): string
    {
        if ($grants->isEmpty()) {
            return 'no access granted';
        }

        if ($grants->contains(fn (Grant $grant) => $grant->project_id === null)) {
            return 'organization-wide';
        }

        $projects = $grants->pluck('project.name')->filter()->unique()->implode(', ');
        $environments = $grants->pluck('environment.slug')->filter()->unique()->implode(', ');

        return $environments === '' ? $projects : "{$projects} · {$environments}";
    }
}
