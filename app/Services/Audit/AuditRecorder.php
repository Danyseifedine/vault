<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single write path into the audit log.
 *
 * Nothing else in the application may create an audit entry: routing every
 * mutation through here is what guarantees the chain stays unbroken and that
 * secret values are encrypted before they are ever persisted.
 */
class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $properties  Safe metadata - stored as-is.
     * @param  array<string, string|null>  $secrets  Sensitive values - encrypted before storage.
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        array $secrets = [],
        ?AuditScope $scope = null,
        ?Model $causer = null,
    ): AuditLog {
        $scope ??= AuditScope::none();

        if ($secrets !== []) {
            $properties['secrets'] = $this->encryptSecrets($secrets);
        }

        return DB::transaction(function () use ($event, $subject, $properties, $scope, $causer): AuditLog {
            $entry = new AuditLog;

            $entry->log_name = config('activitylog.default_log_name');
            $entry->description = $event;
            $entry->event = $event;
            $entry->properties = collect($properties);
            $entry->organization_id = $scope->organizationId;
            $entry->project_id = $scope->projectId;
            $entry->ip = request()->ip();
            $entry->user_agent = Str::limit((string) request()->userAgent(), 250, '');

            if ($subject !== null) {
                $entry->subject()->associate($subject);
            }

            $causer ??= auth()->user();

            if ($causer !== null) {
                $entry->causer()->associate($causer);
            }

            $entry->save();

            return $entry;
        });
    }

    /**
     * Record something that did NOT succeed - a wrong PIN, a denied
     * permission, a lockout. Failures are the entries an investigation
     * actually needs, so they are first-class, never swallowed.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, string|null>  $secrets
     */
    public function failure(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        array $secrets = [],
        ?AuditScope $scope = null,
        ?Model $causer = null,
    ): AuditLog {
        return $this->record(
            event: $event,
            subject: $subject,
            properties: ['failed' => true] + $properties,
            secrets: $secrets,
            scope: $scope,
            causer: $causer,
        );
    }

    /**
     * @param  array<string, string|null>  $secrets
     * @return array<string, string|null>
     */
    private function encryptSecrets(array $secrets): array
    {
        return array_map(
            fn (?string $value) => $value === null ? null : Crypt::encryptString($value),
            $secrets,
        );
    }
}
