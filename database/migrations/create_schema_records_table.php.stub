<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic schema-record store — the narrow core. Populator-specific facts (generation
 * provenance, submission context) belong in reference tables keyed by `schema_records.id`,
 * NOT as columns here (composition, not inheritance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_records');
    }
};
