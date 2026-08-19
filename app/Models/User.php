<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use App\Services\Audit\AuditRecorder;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string|null $name
 * @property string $email
 * @property UserStatus $status
 * @property bool $can_create_organizations
 * @property string|null $job_title
 * @property array<int, string>|null $stack
 * @property Carbon|null $profile_completed_at
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'job_title', 'stack'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Deny by default in memory too, not only in the column default: a model
     * built in code must never answer "null" to "may you start organizations",
     * because null is neither yes nor no and the policy has to decide.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'can_create_organizations' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'status' => UserStatus::class,
            'can_create_organizations' => 'boolean',
            'stack' => 'array',
        ];
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Grant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    /** @return HasMany<Pin, $this> */
    public function pins(): HasMany
    {
        return $this->hasMany(Pin::class);
    }

    public function isInvited(): bool
    {
        return $this->status === UserStatus::Invited;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function hasCompletedProfile(): bool
    {
        return $this->profile_completed_at !== null;
    }

    /** True once every onboarding step is behind them. */
    public function hasFinishedOnboarding(): bool
    {
        return $this->isActive() && $this->hasConfirmedTwoFactor() && $this->hasCompletedProfile();
    }

    /** A short label for audit entries and member lists. */
    public function displayName(): string
    {
        return $this->name ?: $this->email;
    }

    /**
     * A reserved seat must never be claimable through "forgot password" -
     * that would hand the account to anyone who knows the address, bypassing
     * the invitation entirely.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if ($this->isInvited()) {
            app(AuditRecorder::class)->failure(
                'auth.password-reset-refused',
                subject: $this,
                properties: ['reason' => 'account has not accepted its invitation yet'],
                causer: $this,
            );

            return;
        }

        parent::sendPasswordResetNotification($token);
    }
}
