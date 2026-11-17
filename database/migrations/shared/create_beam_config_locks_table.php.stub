<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Facades\Beam;

/**
 * Operator locks (ADR-0245; PRD §Override store "Operator locks are central data"; config-sections ticket 07): one row
 * per locked pointer of one section. A lock NARROWS what the package's declared ceiling allows on this install, never
 * widens it: a locked pointer (and everything below it) refuses a tenant write with the operator's reason, and shows as
 * locked in the tenant's row.
 *
 * Read and written on the `central` connection only. Shipped to the single `database/migrations/shared/` destination
 * like every beam stub (beam has no central-only destination), so the table also lands in each tenant schema, where it
 * is INERT: nothing reads or writes a tenant copy. CONVERGENT, like the config override store's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named(Beam::table('config_locks'))
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('section');                  // the config root, e.g. `beam.ux`
                $table->string('pointer', 1024);            // RFC 6901, relative to the root
                $table->text('reason');                     // shown to the tenant beside the locked key
                $table->string('locked_by')->nullable();    // the operator's key, as a string (int or uuid hosts)
                $table->timestamps();

                $table->unique(['section', 'pointer']);
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('config_locks'));
    }
};
