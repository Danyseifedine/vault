<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * One page of an audit log, whatever the log is scoped to.
 *
 * The organization feed and the project feed disagree about which entries are
 * visible and how a row reads, and about nothing else - so the paging, the
 * success/failure filter and the envelope live here once and each
 * caller passes its own query and its own row mapper.
 *
 * Both the paging and the filter run in SQL, deliberately. The log is the only
 * table in the product with no ceiling, and filtering a single page in the
 * browser would hide the failures sitting on page two - the exact opposite of
 * what an audit screen is for.
 */
final class AuditPage
{
    /** How many entries a page carries by default. */
    public const PER_PAGE = 25;

    /** A URL may ask for a bigger page, but never for the whole table. */
    public const MAX_PER_PAGE = 100;

    /**
     * @param  Builder<AuditLog>  $visible  Already narrowed to what this viewer may read.
     * @param  callable(AuditLog): array<string, mixed>  $row
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     page: int, perPage: int, total: int, lastPage: int, filter: string,
     *     failures: int,
     * }
     */
    public function of(
        Builder $visible,
        callable $row,
        int $page = 1,
        int $perPage = self::PER_PAGE,
        string $filter = 'all',
    ): array {
        $filter = in_array($filter, ['all', 'ok', 'fail'], true) ? $filter : 'all';
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        // Counted over everything visible, NOT the current page or filter:
        // this is what the sidebar badge warns with, and a warning you only
        // meet once you are already looking at failures is no warning.
        $failures = $this->applyFilter(clone $visible, 'fail')->count();

        $query = $this->applyFilter($visible, $filter);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $rows = $query
            ->with('causer')
            ->latest('id')
            ->forPage($page, $perPage)
            ->get()
            ->map($row)
            ->values()
            ->all();

        return compact('rows', 'page', 'perPage', 'total', 'lastPage', 'filter', 'failures');
    }

    /**
     * "Did it fail" is a property rather than a column, so the filter reads
     * into the JSON. `kindFor` also returns 'warn', but that is a shade of a
     * success rather than a third outcome - this filter has two sides.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    private function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'fail' => $query->where('properties->failed', true),
            'ok' => $query->where(fn (Builder $scope) => $scope
                ->whereNull('properties->failed')
                ->orWhere('properties->failed', '!=', true)),
            default => $query,
        };
    }
}
