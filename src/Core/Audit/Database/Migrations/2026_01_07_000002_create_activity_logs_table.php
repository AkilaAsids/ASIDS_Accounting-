<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human-readable activity feed.
 *
 * This is a *product* feature, distinct from `audit_logs` which is a compliance
 * record. The two are separated on purpose:
 *
 *   audit_logs     Every attribute of every change, immutable, seven-year
 *                  retention, read by auditors and incident responders.
 *   activity_logs  A sentence a business user understands ("Nimal approved
 *                  invoice INV-0042"), mutable presentation, short retention,
 *                  read on dashboards.
 *
 * Merging them would force one table to be simultaneously immutable and
 * editable, verbose and readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('company_id')->nullable();

            // Channel name, e.g. "sales", "security", "system".
            $table->string('log_name', 64)->default('default');
            $table->string('event', 64)->nullable();
            $table->text('description');

            // What the activity is about.
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label')->nullable();

            // Who caused it.
            $table->string('causer_type')->nullable();
            $table->uuid('causer_id')->nullable();
            $table->string('causer_label')->nullable();

            $table->jsonb('properties')->nullable();
            // Groups activities produced by one user action into one entry in the
            // feed (for example a bulk approval of forty invoices).
            $table->uuid('batch_id')->nullable();
            $table->uuid('request_id')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'log_name', 'created_at'], 'activity_logs_feed_index');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_logs_subject_index');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_logs_causer_index');
            $table->index('batch_id');
        });

        DB::statement('CREATE INDEX activity_logs_properties_gin ON activity_logs USING gin (properties jsonb_path_ops)');

        DB::statement('COMMENT ON TABLE activity_logs IS \'Product activity feed. Not a compliance record — see audit_logs.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
