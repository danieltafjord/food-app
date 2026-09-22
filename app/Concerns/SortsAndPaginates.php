<?php

namespace App\Concerns;

use Illuminate\Http\Request;

/**
 * Shared parsing of the `sort`, `direction` and `per_page` query parameters
 * that every paginated admin table accepts.
 */
trait SortsAndPaginates
{
    /** @var list<int> */
    private const PAGE_SIZES = [25, 50, 100];

    /**
     * Resolve the sort key and direction, falling back to the defaults when
     * the request asks for a column that is not in the whitelist.
     *
     * @param  array<string, string>  $sorts  sort key => column
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function sorting(Request $request, array $sorts, string $defaultSort, string $defaultDirection = 'desc'): array
    {
        $sort = (string) $request->query('sort', $defaultSort);
        $sort = array_key_exists($sort, $sorts) ? $sort : $defaultSort;

        $direction = (string) $request->query('direction', $defaultDirection);
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection;

        return [$sort, $direction];
    }

    /**
     * The requested page size, limited to the sizes the table offers.
     */
    private function perPage(Request $request, int $default = 50): int
    {
        $perPage = (int) $request->query('per_page', $default);

        return in_array($perPage, self::PAGE_SIZES, true) ? $perPage : $default;
    }

    /** @return list<int> */
    public static function pageSizes(): array
    {
        return self::PAGE_SIZES;
    }
}
