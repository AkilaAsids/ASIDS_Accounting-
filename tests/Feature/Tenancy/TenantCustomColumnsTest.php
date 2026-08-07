<?php

declare(strict_types=1);

use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Schema;

/**
 * The `tenants` table against `Tenant::getCustomColumns()`.
 *
 * stancl/tenancy treats any attribute *not* named in `getCustomColumns()` as virtual and stores it
 * inside the `data` JSONB column instead of writing it to its own column. That is a useful property
 * — feature flags and onboarding state stay out of the migration history — and a dangerous one when
 * the list falls behind the schema.
 *
 * The failure is silent in both directions and neither shows up as an error. A real column missing
 * from the list is written into `data` instead of the column, so the column keeps its default, every
 * query filtering on it silently misses the row, and the value still reads back correctly through
 * the model — so nothing looks wrong until a `WHERE status = 'suspended'` fails to suspend anyone.
 * A name in the list with no column behind it fails on insert instead.
 *
 * This is the one assumption in the Phase 1 status document that no other test reaches, because
 * every other test uses attributes that happen to be listed correctly today.
 */
it('lists every physical column, so none is silently diverted into the data blob', function (): void {
    $columns = Schema::getColumnListing('tenants');

    // `data` itself is the destination, never a member of the list.
    $expected = array_values(array_diff($columns, ['data']));

    sort($expected);

    $declared = Tenant::getCustomColumns();
    sort($declared);

    expect($declared)->toBe($expected);
});

it('writes a declared attribute to its own column rather than into data', function (): void {
    $tenant = RowLevelSecurity::bypass(fn (): Tenant => Tenant::factory()->create([
        'slug' => 'column-check',
        'suspension_reason' => 'Non-payment',
    ]));

    $row = RowLevelSecurity::bypass(
        fn (): object => DB::table('tenants')->where('id', $tenant->getKey())->first(['suspension_reason', 'data']),
    );

    // Read from the raw row, not through the model: the model returns the same value either way,
    // which is exactly what makes this failure invisible from the application's own perspective.
    expect($row->suspension_reason)->toBe('Non-payment')
        ->and((string) ($row->data ?? '{}'))->not->toContain('suspension_reason');
});

it('keeps an undeclared attribute in the data blob, which is the feature', function (): void {
    $tenant = RowLevelSecurity::bypass(function (): Tenant {
        /** @var Tenant $tenant */
        $tenant = Tenant::factory()->create(['slug' => 'blob-check']);

        // Not a column, and deliberately not one: onboarding state changes shape often enough that
        // a migration per field would be noise in the history.
        $tenant->onboarding_step = 'chart-of-accounts';
        $tenant->save();

        return $tenant;
    });

    $row = RowLevelSecurity::bypass(
        fn (): object => DB::table('tenants')->where('id', $tenant->getKey())->first(['data']),
    );

    expect((string) $row->data)->toContain('onboarding_step')
        ->and($tenant->fresh()?->onboarding_step)->toBe('chart-of-accounts');
});
