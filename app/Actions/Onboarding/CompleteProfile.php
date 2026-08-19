<?php

namespace App\Actions\Onboarding;

use App\Models\User;
use App\Services\Audit\AuditRecorder;

class CompleteProfile
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * @param  array{name: string, job_title: string, stack?: array<int, string>|null}  $attributes
     */
    public function __invoke(User $user, array $attributes): User
    {
        $user->forceFill([
            'name' => $attributes['name'],
            'job_title' => $attributes['job_title'],
            'stack' => $attributes['stack'] ?? null,
            'profile_completed_at' => now(),
        ])->save();

        $this->audit->record(
            'onboarding.completed',
            subject: $user,
            properties: ['job_title' => $user->job_title],
            causer: $user,
        );

        return $user;
    }
}
