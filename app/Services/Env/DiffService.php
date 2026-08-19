<?php

namespace App\Services\Env;

use App\Models\Environment;
use App\Models\Variable;

/**
 * Compares two environments key by key.
 *
 * Values are compared server-side and NEVER returned: the caller learns that
 * two values differ, not what either of them is.
 */
class DiffService
{
    public const IN_SYNC = 'in sync';

    public const DIFFERS = 'differs';

    public const MISSING = 'missing';

    /**
     * @return array<int, array{key: string, sensitivity: string, status: string}>
     */
    public function compare(Environment $left, Environment $right): array
    {
        $variables = Variable::query()
            ->where('project_id', $left->project_id)
            ->with(['values' => fn ($query) => $query->whereIn('environment_id', [$left->id, $right->id])])
            ->orderBy('key')
            ->get();

        return $variables->map(function (Variable $variable) use ($left, $right): array {
            $leftValue = $variable->values->firstWhere('environment_id', $left->id);
            $rightValue = $variable->values->firstWhere('environment_id', $right->id);

            return [
                'key' => $variable->key,
                'sensitivity' => $variable->sensitivity->value,
                'status' => $this->statusFor($leftValue?->value, $rightValue?->value),
            ];
        })->all();
    }

    public function missingInRight(Environment $left, Environment $right): int
    {
        return count(array_filter(
            $this->compare($left, $right),
            fn (array $row) => $row['status'] === self::MISSING,
        ));
    }

    private function statusFor(#[\SensitiveParameter] ?string $left, #[\SensitiveParameter] ?string $right): string
    {
        return match (true) {
            $right === null => self::MISSING,
            $left === null => self::MISSING,
            $left !== $right => self::DIFFERS,
            default => self::IN_SYNC,
        };
    }
}
