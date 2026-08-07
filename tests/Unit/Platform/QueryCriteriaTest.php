<?php

declare(strict_types=1);

use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Illuminate\Http\Request;

/**
 * List query parameters.
 *
 * The allow-list here is a security boundary, not a convenience. `?sort=` reaches an ORDER BY and
 * `?filter[]=` reaches a WHERE clause, so an unchecked column name is both an injection vector and
 * an information-disclosure one — sorting by `password` leaks ordering over hashes, and sorting by a
 * column that does not exist leaks the schema through the error.
 *
 * The asymmetry between sorts and filters is deliberate and is asserted below: an unsupported sort
 * is refused, an unknown filter is ignored.
 */
function criteria(
    array $query,
    // `created_at` by default because the default sort is `-created_at` and it is validated like any
    // other: an endpoint that allow-lists sortable columns without including its own default refuses
    // every unsorted request. See the test at the end of the sorting block.
    array $sortable = ['created_at'],
    array $filterable = [],
    array $includable = [],
): QueryCriteria {
    return QueryCriteria::fromRequest(
        Request::create('/api/v1/things', 'GET', $query),
        sortable: $sortable,
        filterable: $filterable,
        includable: $includable,
    );
}

describe('sorting', function (): void {
    it('accepts an allow-listed column', function (): void {
        expect(criteria(['sort' => 'name'], sortable: ['name'])->sorts())->toBe(['name' => 'asc']);
    });

    it('reads a leading minus as descending', function (): void {
        expect(criteria(['sort' => '-created_at'], sortable: ['created_at'])->sorts())
            ->toBe(['created_at' => 'desc']);
    });

    it('accepts several columns in order', function (): void {
        // Order matters: it becomes the order of the ORDER BY clauses.
        expect(criteria(['sort' => '-status,name'], sortable: ['status', 'name'])->sorts())
            ->toBe(['status' => 'desc', 'name' => 'asc']);
    });

    it('refuses a column that is not allow-listed', function (): void {
        $exception = catchPlatformException(fn () => criteria(['sort' => 'password'], sortable: ['name']));

        // Refused, not ignored. A silently dropped sort returns data in an order the client did not
        // ask for and cannot detect, and the endpoint would appear to accept the parameter.
        expect($exception->problemCode())->toBe('unsupported-sort');
    });

    it('refuses a column that is not allow-listed even with a direction prefix', function (): void {
        $exception = catchPlatformException(fn () => criteria(['sort' => '-password'], sortable: ['name']));

        expect($exception->problemCode())->toBe('unsupported-sort');
    });

    it('refuses an injection attempt rather than passing it through', function (): void {
        $exception = catchPlatformException(
            fn () => criteria(['sort' => 'name; DROP TABLE users'], sortable: ['name']),
        );

        // The allow-list is what makes the column name safe to interpolate downstream. Nothing else
        // in the query path escapes an identifier.
        expect($exception->problemCode())->toBe('unsupported-sort');
    });

    it('applies the endpoint’s default when no sort is given', function (): void {
        expect(criteria([], sortable: ['created_at'])->sorts())->toBe(['created_at' => 'desc']);
    });

    it('validates the endpoint’s own default against the allow-list', function (): void {
        // Not a quirk to work around — it is the correct behaviour, and it is worth pinning down
        // because it is a real trap for whoever adds the next endpoint. The default sort is parsed by
        // the same code as a client-supplied one, so an allow-list that omits the default column
        // makes *every* request to that endpoint fail, including the ones sending no sort at all.
        $exception = catchPlatformException(fn () => criteria([], sortable: ['name']));

        expect($exception->problemCode())->toBe('unsupported-sort');
    });

    it('ignores empty segments rather than failing', function (): void {
        expect(criteria(['sort' => 'name,,'], sortable: ['name'])->sorts())->toBe(['name' => 'asc']);
    });
});

describe('filtering', function (): void {
    it('keeps an allow-listed filter', function (): void {
        $result = criteria(['filter' => ['status' => 'active']], filterable: ['status']);

        expect($result->filters())->toBe(['status' => 'active'])
            ->and($result->hasFilter('status'))->toBeTrue();
    });

    it('ignores an unknown filter instead of failing', function (): void {
        $result = criteria(['filter' => ['nonsense' => 'x', 'status' => 'active']], filterable: ['status']);

        // Deliberately different from sorting: a client sending a stale parameter after a deploy
        // should degrade to a less-filtered list, not to an error page.
        expect($result->filters())->toBe(['status' => 'active'])
            ->and($result->hasFilter('nonsense'))->toBeFalse();
    });

    it('drops an empty filter value', function (): void {
        // `?filter[status]=` is what an unset select posts. Treating it as a filter would return
        // nothing at all and read as "there is no data".
        expect(criteria(['filter' => ['status' => '']], filterable: ['status'])->filters())->toBe([]);
    });

    it('trims a filter value', function (): void {
        expect(criteria(['filter' => ['status' => '  active  ']], filterable: ['status'])->filters())
            ->toBe(['status' => 'active']);
    });

    it('ignores a filter parameter that is not an array', function (): void {
        expect(criteria(['filter' => 'active'], filterable: ['status'])->filters())->toBe([]);
    });
});

describe('includes', function (): void {
    it('keeps allow-listed relations', function (): void {
        $result = criteria(['include' => 'roles,memberships'], includable: ['roles', 'memberships']);

        expect($result->includes())->toBe(['roles', 'memberships'])
            ->and($result->hasInclude('roles'))->toBeTrue();
    });

    it('silently drops a relation that is not allow-listed', function (): void {
        // An arbitrary relation name reaches `with()`, so this bounds both the query cost and what
        // a client can pull back through an endpoint that was not designed to expose it.
        expect(criteria(['include' => 'roles,secrets'], includable: ['roles'])->includes())->toBe(['roles']);
    });

    it('returns no includes when the parameter is absent', function (): void {
        expect(criteria([], includable: ['roles'])->includes())->toBe([]);
    });
});

describe('search', function (): void {
    it('accepts a term of two characters or more', function (): void {
        expect(criteria(['q' => 'ab'])->search())->toBe('ab');
    });

    it('ignores a single character', function (): void {
        // Below two characters a trigram search matches nearly everything, so it costs more than it
        // helps and returns a page of noise.
        expect(criteria(['q' => 'a'])->search())->toBeNull();
    });

    it('trims and then measures', function (): void {
        expect(criteria(['q' => '  a  '])->search())->toBeNull();
    });

    it('caps the term length', function (): void {
        $result = criteria(['q' => str_repeat('x', 500)])->search();

        expect($result)->not->toBeNull()
            ->and(mb_strlen((string) $result))->toBe(120);
    });

    it('counts characters rather than bytes', function (): void {
        // Sinhala is the point of this: `mb_strlen` on "ශ්‍රී" is a handful of characters and rather
        // more bytes, and a byte-based minimum would reject a legitimate two-character search.
        expect(criteria(['q' => 'ශ්'])->search())->toBe('ශ්');
    });
});

describe('pagination', function (): void {
    it('applies the configured default', function (): void {
        config(['asids.api.pagination' => ['default_per_page' => 25, 'max_per_page' => 200]]);

        expect(criteria([])->perPage())->toBe(25);
    });

    it('caps a page size above the maximum', function (): void {
        config(['asids.api.pagination' => ['default_per_page' => 25, 'max_per_page' => 200]]);

        // Uncapped, `?per_page=100000` is a denial of service anyone can issue with a browser.
        expect(criteria(['per_page' => '100000'])->perPage())->toBe(200);
    });

    it('floors a page size below one', function (): void {
        config(['asids.api.pagination' => ['default_per_page' => 25, 'max_per_page' => 200]]);

        // `per_page=0` would divide by zero in the paginator.
        expect(criteria(['per_page' => '0'])->perPage())->toBe(1)
            ->and(criteria(['per_page' => '-5'])->perPage())->toBe(1);
    });

    it('floors the page number at one', function (): void {
        expect(criteria(['page' => '0'])->page())->toBe(1)
            ->and(criteria(['page' => '-3'])->page())->toBe(1);
    });
});

describe('direct construction', function (): void {
    it('builds criteria without a request, for scheduled work', function (): void {
        $result = QueryCriteria::of(sorts: ['name' => 'asc'], filters: ['status' => 'active'], perPage: 500);

        // A nightly report is not an HTTP request and must not have to fabricate one — nor should it
        // be capped by the API's page size, which exists to protect the API.
        expect($result->sorts())->toBe(['name' => 'asc'])
            ->and($result->filters())->toBe(['status' => 'active'])
            ->and($result->perPage())->toBe(500);
    });
});
