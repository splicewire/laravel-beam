<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;

/**
 * The polymorphic durable-version store that `rushing/laravel-versioning`'s Versionable / VersionStore
 * read and write — the frozen snapshots (milestones) a particle can be rolled back to. Shipped as a
 * publish-only spatie/laravel-package-tools stub (`runsMigrations` FALSE): the package publishes this
 * timestamp-less `.php.stub` via `configurePackage()`'s `->hasMigrations([...])`, which re-stamps +
 * sequences it into the host at install time; the host runs it. beam-core never loadMigrationsFrom's it.
 *
 * SHARED (central + every tenant): published to the single `database/migrations/shared/` destination,
 * which the host's tenancy substrate (beam-tenancy's `registerSharedMigrationsPath()`) registers into
 * BOTH the central `migrate` pass and Stancl's tenant pass — one file, so `beam_versions` exists
 * identically everywhere with no hand-duplicated DDL to keep in sync. A fresh store starts empty, so
 * the retired `versions`→`beam_versions` rename shim + the `alias_particle_morph` data-migration are
 * dropped — this is a clean greenfield create.
 *
 * Homed in beam-CORE, not beam-versioning (research/01 C13/C15 — "versioning is beam tier"): beam-core
 * owns the `Beam::table()` prefix seam and sets `config('versioning.table')` to this name in
 * packageRegistered(); beam-versioning is the naming-agnostic HTTP/DTO tier over the foundation and
 * takes no beam-core dependency, so the table's create belongs with its naming authority.
 *
 * The record IS its own lineage: `versionable_type`/`versionable_id` is the morph (a BeamParticle
 * stamps `beam_particle` via its getMorphClass + the additive morphMap in BeamServiceProvider), `version`
 * a per-record monotonic integer, `snapshot` the frozen content. HEAD is a pin on the RECORD (the
 * `head_version` column on `beam_particles`), not a per-row flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ubiquitous-table guard: a host that migrates BOTH the central and the tenant pass into ONE
        // schema (the shared-test-DB harness) would otherwise re-create this table. In production the
        // passes target separate schemas, so the guard is simply false. Mirrors the app's own ubiquitous
        // tables (e.g. tenant/create_users_table).
        if (Schema::hasTable(Beam::table('versions'))) {
            return;
        }

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');       // the morph — the record this version belongs to
            $table->string('versionable_id');
            $table->unsignedInteger('version');        // per-record monotonic: 1, 2, 3…
            $table->json('snapshot');                  // the frozen content (Versionable::toVersionSnapshot())
            $table->string('label')->nullable();       // human annotation — the one mutable column
            $table->uuid('created_by')->nullable();    // actor
            $table->timestamps();

            $table->unique(['versionable_type', 'versionable_id', 'version']); // monotonic correctness backstop
            $table->index(['versionable_type', 'versionable_id']);             // history lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('versions'));
    }
};
