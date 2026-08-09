<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Source\SourceWriteTier;

/**
 * The generic particle store — beam's narrow schema-driven-CMS core (ADR-0138). Shipped as a
 * publish-only spatie/laravel-package-tools stub (`runsMigrations` FALSE): the package publishes
 * this timestamp-less `.php.stub` via `configurePackage()`'s `->hasMigrations([...])` (`vendor:publish
 * --tag=beam-migrations`), which re-stamps + sequences it into the host at install time. beam-core
 * never loadMigrationsFrom's it at runtime.
 *
 * SHARED (central + every tenant): published to the single `database/migrations/shared/` destination,
 * which the host's tenancy substrate (beam-tenancy's `registerSharedMigrationsPath()`) registers into
 * BOTH the central `migrate` pass and Stancl's tenant pass — one file, so `beam_particles` exists
 * identically everywhere with no hand-duplicated DDL to keep in sync. The BeamParticle model pins NO
 * connection (it follows the ambient central/tenant connection).
 *
 * The versioning columns are the trait defaults: `schema_id` (the absolute `$id` the payload was
 * written under — the migrate-on-read version column), `migration_status` (current/pending/failed),
 * and `head_version` (the Versionable snapshot HEAD pin). Distinct from `schema_ref`, the declared
 * schema BINDING.
 *
 * SOURCE PROVENANCE + LIFECYCLE TIER (sourced-particles ticket 06, ADR-0161 Position 1 — folded in
 * here, no longer a separate ALTER). A shadow is the SAME row marked with WHERE it came from and
 * WHICH tier it sits in:
 *  - `source_tier`       — {@see SourceWriteTier}: local | shadow-cached | shadow-pinned.
 *  - `external_ref`      — the item's opaque id at its foreign authority (null for a local row).
 *  - `source_revision`   — the CONTENT pin.
 *  - `projector_version` — the SHAPE pin (the double-pin).
 *  - `fetched_at`        — staleness signal.
 *
 * The partial UNIQUE index `(external_ref, source_revision) WHERE external_ref IS NOT NULL` is the
 * idempotency key: one shadow per foreign item per revision; NULL local rows unconstrained.
 * Table name routes through {@see Beam::table()} so a retrofit host's one prefix override follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ubiquitous-table guard: a host that migrates BOTH the central and the tenant pass into ONE
        // schema (the shared-test-DB harness) would otherwise re-create this table. In production the
        // passes target separate schemas, so the guard is simply false. Mirrors the app's own ubiquitous
        // tables (e.g. tenant/create_users_table).
        if (Schema::hasTable(Beam::table('particles'))) {
            return;
        }

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();

            // Source provenance + lifecycle tier (ticket 06).
            $table->string('source_tier')->default('local')->index();
            $table->string('external_ref')->nullable();
            $table->string('source_revision')->nullable();
            $table->string('projector_version')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->timestamps();
        });

        // Partial UNIQUE: one shadow per (external_ref, source_revision); local rows (NULL external_ref)
        // unconstrained. Raw statement — Blueprint has no partial-index DSL. Postgres-shaped (sqlite
        // honours the same syntax); a host on another engine adapts.
        DB::statement(
            'CREATE UNIQUE INDEX '.Beam::table('particles').'_external_ref_source_revision_unique '
            .'ON '.Beam::table('particles').' (external_ref, source_revision) '
            .'WHERE external_ref IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.Beam::table('particles').'_external_ref_source_revision_unique');
        Schema::dropIfExists(Beam::table('particles'));
    }
};
