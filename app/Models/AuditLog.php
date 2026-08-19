<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * A single tamper-evident audit entry.
 *
 * The chain hash is applied on `creating` rather than in AuditRecorder so that
 * ANY path which writes an activity - ours, or spatie's automatic model
 * logging - is linked into the chain and cannot silently escape it.
 *
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $organization_id
 * @property int|null $project_id
 * @property string|null $previous_hash
 * @property string|null $hash
 * @property CarbonImmutable|null $created_at The app uses immutable dates throughout
 */
class AuditLog extends Activity
{
    /** Fields hashed into the chain, in a fixed order. */
    private const CHAINED = [
        'log_name', 'description', 'event',
        'subject_type', 'subject_id',
        'causer_type', 'causer_id',
        'properties', 'ip', 'user_agent',
        'organization_id', 'project_id',
        'batch_uuid', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            // Eloquent sets timestamps AFTER `creating`, so stamp it here to
            // make the value part of the hash deterministic.
            $entry->created_at ??= now();

            $previous = static::query()->orderByDesc('id')->lockForUpdate()->first();

            $entry->previous_hash = $previous?->hash;
            $entry->hash = $entry->computeChainHash();
        });
    }

    /**
     * Recompute this entry's hash from what is actually stored.
     *
     * Uses raw attributes so a freshly built row and a row read back from the
     * database hash identically - that equality is what makes verification work.
     */
    public function computeChainHash(): string
    {
        $raw = $this->getAttributes();

        $payload = [];

        foreach (self::CHAINED as $field) {
            $value = $raw[$field] ?? null;

            $payload[$field] = match (true) {
                $field === 'created_at' && $value !== null => $this->normalizeTimestamp($value),
                $field === 'properties' => $this->normalizeProperties($value),
                $value === null => null,
                default => (string) $value,
            };
        }

        $payload['previous_hash'] = $raw['previous_hash'] ?? null;

        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeTimestamp(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : Str::before((string) $value, '.');
    }

    /**
     * Reduce the properties to a canonical form before hashing.
     *
     * A freshly built row holds them as a collection while a row read back
     * holds whatever JSON text the database chose to store - different
     * spacing, possibly different key order. Hashing the decoded content
     * instead means the chain tracks what actually changed, not how the
     * storage engine happened to format it.
     */
    private function normalizeProperties(mixed $value): string
    {
        $decoded = match (true) {
            $value === null => [],
            is_string($value) => json_decode($value, associative: true) ?? [],
            $value instanceof Collection => $value->toArray(),
            is_array($value) => $value,
            default => [],
        };

        $this->sortByKeyDeep($decoded);

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param  array<mixed>  $data */
    private function sortByKeyDeep(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortByKeyDeep($value);
            }
        }

        unset($value);

        ksort($data);
    }

    /**
     * Who did this, ready for display. System events have no causer, and
     * that is a real answer rather than a blank.
     */
    public function actorName(): string
    {
        $causer = $this->causer;

        return $causer instanceof User ? $causer->displayName() : 'system';
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Personal-vault activity: never tied to an organization or project.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopePersonal(Builder $query): Builder
    {
        return $query->whereNull('organization_id')->whereNull('project_id');
    }
}
