<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Domain\Query;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Http\Request;

/**
 * Validated, allow-listed list query parameters.
 *
 * Built from a request but not coupled to one: a scheduled report constructs a
 * QueryCriteria directly. The allow-list is the security property — `?sort=` and
 * `?filter[]=` reach a SQL ORDER BY and WHERE clause, so accepting arbitrary
 * column names would be an injection and an information-disclosure vector (a
 * client could sort by `password` and infer ordering).
 */
final readonly class QueryCriteria
{
    /**
     * @param  array<string, 'asc'|'desc'>  $sorts
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $includes
     */
    private function __construct(
        private array $sorts,
        private array $filters,
        private array $includes,
        private ?string $search,
        private int $perPage,
        private int $page,
    ) {}

    /**
     * @param  list<string>  $sortable
     * @param  list<string>  $filterable
     * @param  list<string>  $includable
     */
    public static function fromRequest(
        Request $request,
        array $sortable = [],
        array $filterable = [],
        array $includable = [],
        string $defaultSort = '-created_at',
    ): self {
        return new self(
            sorts: self::parseSorts((string) $request->query('sort', $defaultSort), $sortable),
            filters: self::parseFilters($request, $filterable),
            includes: self::parseIncludes($request, $includable),
            search: self::parseSearch($request),
            perPage: self::parsePerPage($request),
            page: max(1, (int) $request->query('page', 1)),
        );
    }

    /**
     * @param  array<string, 'asc'|'desc'>  $sorts
     * @param  array<string, mixed>  $filters
     */
    public static function of(
        array $sorts = ['created_at' => 'desc'],
        array $filters = [],
        int $perPage = 25,
        int $page = 1,
    ): self {
        return new self($sorts, $filters, [], null, $perPage, $page);
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    public function sorts(): array
    {
        return $this->sorts;
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    public function hasFilter(string $key): bool
    {
        return array_key_exists($key, $this->filters);
    }

    /**
     * @return list<string>
     */
    public function includes(): array
    {
        return $this->includes;
    }

    public function hasInclude(string $relation): bool
    {
        return in_array($relation, $this->includes, true);
    }

    public function search(): ?string
    {
        return $this->search;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function page(): int
    {
        return $this->page;
    }

    /**
     * `?sort=-created_at,name` — a leading minus means descending, mirroring the
     * JSON:API convention.
     *
     * @param  list<string>  $sortable
     * @return array<string, 'asc'|'desc'>
     */
    private static function parseSorts(string $raw, array $sortable): array
    {
        $sorts = [];

        foreach (explode(',', $raw) as $field) {
            $field = trim($field);

            if ($field === '') {
                continue;
            }

            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-+');

            if (! in_array($column, $sortable, true)) {
                throw BusinessRuleViolation::make(
                    code: 'unsupported-sort',
                    message: sprintf('Sorting by "%s" is not supported on this endpoint.', $column),
                    context: ['sortable' => $sortable],
                );
            }

            $sorts[$column] = $direction;
        }

        return $sorts;
    }

    /**
     * @param  list<string>  $filterable
     * @return array<string, mixed>
     */
    private static function parseFilters(Request $request, array $filterable): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->query('filter', []);

        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! in_array($key, $filterable, true)) {
                // Unknown filters are ignored rather than rejected: a client
                // sending a stale parameter after a deploy should degrade to an
                // unfiltered list, not to an error page.
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $filters[$key] = is_string($value) ? trim($value) : $value;
        }

        return $filters;
    }

    /**
     * @param  list<string>  $includable
     * @return list<string>
     */
    private static function parseIncludes(Request $request, array $includable): array
    {
        $raw = (string) $request->query('include', '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $relation): bool => in_array($relation, $includable, true),
        ));
    }

    private static function parseSearch(Request $request): ?string
    {
        $search = trim((string) $request->query('q', ''));

        // Two characters is the shortest term worth an index scan; below that a
        // trigram search matches nearly everything and costs more than it helps.
        return mb_strlen($search) >= 2 ? mb_substr($search, 0, 120) : null;
    }

    private static function parsePerPage(Request $request): int
    {
        /** @var array{default_per_page:int, max_per_page:int} $config */
        $config = config('asids.api.pagination');

        $requested = (int) $request->query('per_page', (string) $config['default_per_page']);

        return max(1, min($requested, $config['max_per_page']));
    }
}
