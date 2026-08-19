<?php

namespace App\Services\Projects;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Names inside a project must be distinguishable to a person.
 *
 * Slugs alone cannot enforce this: sluggable happily turns a second "Database"
 * into `database-1`, which reads like a mistake in a list of groups. The
 * comparison is case-insensitive for the same reason - "Redis" and "redis" are
 * the same name to everyone except the database.
 */
final class UniqueNameGuard
{
    /**
     * @template TSibling of Model
     *
     * @param  Collection<int, TSibling>  $siblings  Everything the new name must differ from.
     */
    public static function guard(Collection $siblings, string $name, string $noun, ?int $ignoreId = null): void
    {
        $taken = $siblings->contains(
            fn (Model $sibling) => $sibling->getKey() !== $ignoreId
                && Str::lower((string) $sibling->getAttribute('name')) === Str::lower($name),
        );

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This project already has a {$noun} called \"{$name}\".",
            ]);
        }
    }
}
