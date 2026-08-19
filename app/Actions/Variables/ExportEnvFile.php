<?php

namespace App\Actions\Variables;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Builds a merged .env for one environment.
 *
 * An export is a bulk reveal - same permission, same audit trail.
 */
class ExportEnvFile
{
    public function __construct(
        private AccessResolver $access,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Environment $environment, User $user): string
    {
        if (! $this->access->can($user, Permission::ExportEnv, $environment)) {
            $this->audit->failure(
                'export.denied',
                properties: ['environment' => $environment->slug],
                scope: AuditScope::make($environment->project->organization_id, $environment->project_id),
                causer: $user,
            );

            throw new AuthorizationException('You do not have permission to export this environment.');
        }

        $values = $environment->values()
            ->with('variable')
            ->get()
            ->sortBy(fn ($value) => $value->variable->key);

        $lines = $values->map(fn ($value) => $value->variable->key.'='.$this->quote($value->value));

        $this->audit->record(
            'export.generated',
            properties: [
                'environment' => $environment->slug,
                'variables' => $values->count(),
            ],
            scope: AuditScope::make($environment->project->organization_id, $environment->project_id),
            causer: $user,
        );

        return $lines->implode("\n")."\n";
    }

    /** Quote anything that would not survive a round trip unquoted. */
    private function quote(#[\SensitiveParameter] string $value): string
    {
        if ($value === '' || preg_match('/[\s"\'#$]/', $value)) {
            return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
        }

        return $value;
    }
}
