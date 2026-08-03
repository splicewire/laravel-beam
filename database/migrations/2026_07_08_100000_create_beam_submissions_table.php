<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Models\BeamSubmission;

/**
 * The shared submission ledger — beam-core's first schema-driven-CMS consumer (ADR-0138). One
 * {@see BeamSubmission} model that migrates-on-read as its form schema evolves. Shipped as a
 * publish-only migration (`runsMigrations` FALSE): the package ships this real-timestamped copy and
 * publishes it VERBATIM into the host via native `publishesMigrations` (natural timestamp preserved,
 * no re-stamp); the host runs it. beam-core never loadMigrationsFrom's it.
 *
 * UBIQUITOUS table (central + every tenant): ships as TWO copies (this flat one → central pass; its
 * `tenant/` twin → Stancl tenant pass), so `beam_submissions` exists identically in both (marketing
 * leads / relay inbound land central; circuit intake lands tenant-side inside its own isolation). A
 * fresh store starts empty, so the retired `form_submissions`→`beam_submissions` rename shim is
 * dropped — this is a clean greenfield create.
 *
 * Homed in beam-CORE (research/01 C14): the retired `laravel-beam-submissions` package's model became
 * beam-core's {@see BeamSubmission}, whose getTable() resolves `Beam::table('submissions')`, so the
 * table's create belongs with it.
 *
 * Migrate-on-read (ADR-0138): `schema_id` = the absolute `$id` the payload was captured under;
 * `migration_status` = current/pending/failed. No `head_version` — a submission is migrate-on-read
 * only (immutable capture, not a snapshot-versioned doc). `meta` carries schema-form-agnostic derived
 * facts (RecordsSubmissions stamps the resolved form schema under `meta.schema` for the notify path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Beam::table('submissions'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('form_key')->index();
            $table->string('schema_ref')->nullable();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->json('payload');
            $table->json('context')->nullable();
            $table->json('meta')->nullable();
            $table->uuid('user_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('submissions'));
    }
};
