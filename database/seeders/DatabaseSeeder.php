<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeding is split by what the data *is*, not by table:
 *
 *   PermissionSeeder    Reference data the application cannot function without. Safe and
 *                       required in every environment, including production.
 *   DemoWorkspaceSeeder Sample data. Refuses to run outside local and staging.
 *
 * Conflating them is how a demo workspace ends up in a customer's production database.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        if (app()->environment('local', 'staging', 'testing')) {
            $this->call(DemoWorkspaceSeeder::class);
        }
    }
}
