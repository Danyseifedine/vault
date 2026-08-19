<?php

namespace App\Enums;

/**
 * Where a grant row sits: the whole organization, one project, or one
 * environment. A row covers everything beneath its scope - including projects
 * and environments created after it was written.
 */
enum GrantScope: string
{
    case Organization = 'organization';
    case Project = 'project';
    case Environment = 'environment';

    /**
     * May a grant ROW written at $rowScope carry a permission native to $this?
     *
     * Org rows may carry anything (the permission then applies everywhere it
     * means something); project rows carry project- and environment-native
     * permissions; environment rows carry environment actions only. The other
     * direction is nonsense: "rename the organization, but only in prod".
     */
    public function coveredBy(self $rowScope): bool
    {
        return match ($rowScope) {
            self::Organization => true,
            self::Project => $this !== self::Organization,
            self::Environment => $this === self::Environment,
        };
    }
}
