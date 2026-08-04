<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT TWIN of the ubiquitous, UUID-morph `media` table. Identical DDL to the flat
 * `create_media_table.php`; this copy publishes into the host's `database/migrations/tenant/` so the
 * Stancl tenant pass creates `media` in every tenant schema, while the flat copy's central pass creates
 * it in the central schema. Publish-only (`beam-migrations` tag) — beam-core never runs it at runtime.
 *
 * See the flat copy for the full rationale (beam-core owns media because it requires medialibrary +
 * defines the media traits; `uuidMorphs` because beam is UUID-native and spatie's default bigint
 * `model_id` breaks a UUID media owner on a strict driver).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ubiquitous-table guard: a host that migrates BOTH the central and the tenant pass into ONE
        // schema (the shared-test-DB harness) would otherwise re-create this table. In production the
        // passes target separate schemas, so the guard is simply false. Mirrors beam's other ubiquitous
        // twins (e.g. tenant/create_beam_versions_table).
        if (Schema::hasTable('media')) {
            return;
        }

        Schema::create('media', function (Blueprint $table): void {
            $table->id();

            $table->uuidMorphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
