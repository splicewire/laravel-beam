<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spatie/laravel-medialibrary `media` table — owned by beam-core rather than published verbatim
 * from spatie's stub, so the polymorphic `model_id` is a UUID. Shipped as a publish-only migration
 * (like every beam-core substrate table): the package ships this real-timestamped copy and publishes
 * it VERBATIM into the host via native `publishesMigrations` (`vendor:publish --tag=beam-migrations`) —
 * beam-core never loadMigrationsFrom's it at runtime — and the HOST runs it.
 *
 * WHY beam-core owns it: beam-core requires spatie/laravel-medialibrary and defines the media traits
 * (HasPrimaryImages / HasFeaturedImage), so the media table is core infrastructure — any beam install
 * that attaches media needs it, with or without the CMS publishing layer. Beam is UUID-native: every
 * media-owning model in the estate (publishing's Post, satellite models like AudioSample / Composition)
 * carries a UUID key. Spatie's default `$table->morphs('model')` makes `model_id` a BIGINT — which
 * silently "works" on SQLite's loose typing but throws `invalid input syntax for type bigint` on a
 * strict driver (Postgres) the moment a UUID owner attaches media. `uuidMorphs` is the spatie-blessed
 * fix for UUID owners and the honest expression of beam's UUID-native constraint.
 *
 * UBIQUITOUS table (central + every tenant): this same DDL ships as TWO copies — this flat one lands in
 * the host's `database/migrations/` (central pass) and its `tenant/` twin lands in
 * `database/migrations/tenant/` (Stancl tenant pass) — so `media` exists identically in the central
 * schema and every tenant schema (a tenant-scoped Post's featured image lives in the tenant schema; a
 * central model's media in the central schema). The `2026_06_30_000050` prefix lands it before
 * publishing's `posts`/`categories` — no ordering dependency, media just settles early.
 */
return new class extends Migration
{
    public function up(): void
    {
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
