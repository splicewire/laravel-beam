<?php

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Tests\TestCase;

class SavedFiltersMigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    private function migrate(): void
    {
        (require dirname(__DIR__, 2).'/database/migrations/shared/create_saved_filters_table.php.stub')->up();
    }

    public function test_publishing_uses_the_existing_beam_migrations_shared_destination(): void
    {
        $matches = array_filter(ServiceProvider::pathsToPublish(BeamServiceProvider::class, 'beam-migrations'),
            fn ($destination, $source) => str_ends_with($source, '/shared/create_saved_filters_table.php.stub'), ARRAY_FILTER_USE_BOTH);
        $this->assertCount(1, $matches);
        $this->assertStringStartsWith(database_path('migrations/shared/'), array_values($matches)[0]);
    }

    public function test_a_fresh_table_accepts_integer_and_uuid_owners_and_rejects_duplicate_defaults(): void
    {
        $this->migrate();
        foreach (['42', (string) Str::uuid()] as $owner) {
            SavedFilter::create(['name' => 'Default', 'resource' => 'papers', 'query_parameters' => [],
                'owner_type' => 'user', 'owner_id' => $owner, 'is_default' => true]);
        }
        $this->assertSame(2, SavedFilter::count());
        $this->migrate();
        $this->assertSame(2, SavedFilter::count());
        $this->expectException(QueryException::class);
        SavedFilter::create(['name' => 'Collision', 'resource' => 'papers', 'query_parameters' => [],
            'owner_type' => 'user', 'owner_id' => '42', 'is_default' => true]);
    }

    public function test_existing_uuid_schema_and_records_keep_their_columns_and_default_index(): void
    {
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('resource');
            $table->json('query_parameters');
            $table->nullableUuidMorphs('owner');
            $table->string('visibility')->default('private');
            $table->nullableUuidMorphs('context');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['resource', 'owner_type', 'owner_id']);
        });
        DB::statement('CREATE UNIQUE INDEX saved_filters_one_default_per_owner_resource ON saved_filters (owner_type, owner_id, resource) WHERE is_default');
        $saved = SavedFilter::create(['name' => 'Existing', 'resource' => 'papers', 'query_parameters' => [],
            'owner_type' => 'user', 'owner_id' => (string) Str::uuid(), 'is_default' => true]);
        $columns = Schema::getColumns('saved_filters');
        $indexes = Schema::getIndexes('saved_filters');
        $this->migrate();
        $this->assertSame($columns, Schema::getColumns('saved_filters'));
        $this->assertSame($indexes, Schema::getIndexes('saved_filters'));
        $this->assertSame('Existing', $saved->fresh()->name);
    }

    public function test_partial_existing_table_gets_the_missing_optional_shape_without_losing_records(): void
    {
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('resource');
            $table->json('query_parameters');
        });
        DB::table('saved_filters')->insert(['id' => (string) Str::uuid(), 'name' => 'Retained', 'resource' => 'papers', 'query_parameters' => '{}']);
        $this->migrate();
        $this->assertTrue(Schema::hasColumns('saved_filters', ['owner_id', 'context_id', 'visibility', 'is_default']));
        $this->assertSame('Retained', DB::table('saved_filters')->value('name'));
    }
}
