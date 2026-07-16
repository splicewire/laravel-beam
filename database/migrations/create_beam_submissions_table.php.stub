<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic submission reference table — a beam-native companion to `schema_records`
 * (composition, not inheritance). Carries ONLY facets any beam app with user input produces;
 * NO `form_key` (the referenced record already bears its `schema_ref`). Domain-specific
 * submission facts ride the owning domain package's table, not this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('beam.tables.submissions', 'beam_submissions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            // FK → the narrow record this submission references. Not a hard DB constraint:
            // a host may point the reference at its own PersistsSchemaRecord model whose table
            // this package does not own.
            $table->uuid('schema_record_id')->index();
            $table->uuid('submitted_by')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->string('source')->nullable();
            $table->string('channel')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('beam.tables.submissions', 'beam_submissions'));
    }
};
