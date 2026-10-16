<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\MigrationOutcome;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Collections\CollectionBacking;
use Splicewire\Beam\Collections\CollectionMigrationDrain;
use Splicewire\Beam\Collections\CollectionRead;
use Splicewire\Beam\Collections\CollectionRelations;
use Splicewire\Beam\Collections\CollectionRoutes;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;

class CollectionReadTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('g', 32));
        $app['config']->set('frame.middleware', ['web']);
    }

    private const STEM = 'https://example.test/articles';

    private DatabaseSchemaRegistry $registry;

    private CollectionRead $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create(Beam::table('schemas'), function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('schema_id')->unique();
            $t->string('schema_name');
            $t->integer('version');
            $t->string('fingerprint');
            $t->json('artifact');
            $t->timestamps();
        });
        Schema::create(Beam::table('particles'), function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('schema_ref');
            $t->string('schema_id');
            $t->string('source_tier')->default('local');
            $t->string('migration_status')->default('current');
            $t->json('payload');
            $t->string('head_version')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
        $this->registry = new DatabaseSchemaRegistry;
        $reconciler = Mockery::mock(RecordReconciler::class);
        $reconciler->shouldReceive('currentId')->andReturnUsing(fn ($stem) => $stem.'/1');
        $reconciler->shouldReceive('reconcile')->andReturnUsing(fn ($payload, $id) => MigrationOutcome::current($id));
        $this->app->instance(RecordReconciler::class, $reconciler);
        $this->reader = new CollectionRead(new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry), app(StandaloneRecordQuery::class));
    }

    private function register(bool $public = true, int $version = 1, bool $publicRelation = true): void
    {
        $schema = ['$id' => self::STEM.'/'.$version, 'type' => 'object',
            'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'title'],
            'properties' => ['title' => ['type' => 'string', 'x-filter' => ['name' => 'title', 'operator' => 'exact', 'control' => 'text'], 'x-sort' => ['name' => 'title', 'default' => true, 'direction' => 'desc']], 'secret' => ['type' => 'string'], 'visible' => ['type' => 'boolean'], 'related' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-beam-relation' => ['collection' => self::STEM]]]];
        if ($public) {
            $schema['x-beam-public-read'] = ['fields' => $publicRelation ? ['title', 'related'] : ['title'], 'where' => ['all' => [['field' => 'visible', 'operator' => 'equals', 'value' => true]]]];
        }
        $this->registry->register($schema);
    }

    private function insert(string $id, bool $visible = true, string $status = 'current', string $tier = 'local', ?string $ref = null): void
    {
        DB::table(Beam::table('particles'))->insert(['id' => $id, 'schema_ref' => $ref ?? self::STEM.'/1', 'schema_id' => self::STEM.'/1', 'source_tier' => $tier,
            'migration_status' => $status, 'payload' => json_encode(['title' => $id, 'secret' => 'private', 'visible' => $visible]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guest_batch_and_detail_share_field_and_row_narrowing(): void
    {
        $this->register();
        $this->insert('public');
        $this->insert('private', false);
        $rows = $this->reader->many(self::STEM, ['public', 'private'], null);
        $this->assertSame(['public'], array_keys($rows));
        $this->assertSame(['title' => 'public'], $rows['public']->fields);
        $this->assertNull($this->reader->find(self::STEM, 'private', null));
        $this->assertSame('private', $this->reader->find(self::STEM, 'private', new User)->fields['secret']);
    }

    public function test_unmarked_public_read_and_latest_revocation_admit_no_guest(): void
    {
        $this->register();
        $this->insert('public');
        $this->assertNotNull($this->reader->find(self::STEM, 'public', null));
        $this->register(false, 2);
        $this->assertNull($this->reader->find(self::STEM, 'public', null));
    }

    public function test_pending_failed_shadow_and_another_collection_are_not_guest_records(): void
    {
        $this->register();
        $this->insert('pending', status: 'pending');
        $this->insert('failed', status: 'failed');
        $this->insert('shadow', tier: 'shadow-cached');
        $this->insert('other', ref: self::STEM.'/child/1');
        $this->assertSame([], $this->reader->many(self::STEM, ['pending', 'failed', 'shadow', 'other'], null));
        $pending = $this->reader->find(self::STEM, 'pending', new User);
        $this->assertSame('pending', $pending->migrationStatus);
    }

    public function test_relation_batch_is_one_record_query_not_one_query_per_id(): void
    {
        $this->register();
        foreach (range(1, 12) as $id) {
            $this->insert((string) $id);
        }
        DB::enableQueryLog();
        $rows = $this->reader->many(self::STEM, array_map('strval', range(1, 12)), null);
        $queries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "'.Beam::table('particles').'"'));
        $this->assertCount(12, $rows);
        $this->assertCount(1, $queries);
    }

    public function test_relation_targets_apply_their_own_public_rule_and_batch_once(): void
    {
        $this->register();
        $this->insert('source');
        $this->insert('target');
        $this->insert('private', false);
        DB::table(Beam::table('particles'))->where('id', 'source')->update(['payload' => json_encode(['title' => 'source', 'visible' => true, 'related' => ['target', 'private', 'missing']])]);
        $source = $this->reader->find(self::STEM, 'source', null);
        $relations = new CollectionRelations(new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry), $this->reader);
        $result = $relations->resolve([$source], null);
        $this->assertSame(['target'], array_map(fn ($r) => $r->id, $result['source']['related']));
        $this->assertSame(['title' => 'target'], $result['source']['related'][0]->fields);
    }

    public function test_a_relation_not_in_public_fields_is_not_traversed(): void
    {
        $this->register(publicRelation: false);
        $this->insert('source');
        $this->insert('target');
        DB::table(Beam::table('particles'))->where('id', 'source')->update(['payload' => json_encode(['title' => 'source', 'visible' => true, 'related' => ['target']])]);
        $source = $this->reader->find(self::STEM, 'source', null);
        $relations = new CollectionRelations(new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry), $this->reader);
        $this->assertSame(['source' => []], $relations->resolve([$source], null));
    }

    public function test_guest_query_scope_intersects_and_excludes_a_new_pending_transition(): void
    {
        $this->register();
        $this->insert('public');
        $this->insert('private', false);
        $this->insert('transition');
        $reconciler = Mockery::mock(RecordReconciler::class);
        $reconciler->shouldReceive('currentId')->andReturnUsing(fn ($stem) => $stem.'/1');
        $reconciler->shouldReceive('reconcile')->andReturnUsing(fn ($payload, $id) => $payload['title'] === 'transition'
            ? MigrationOutcome::pending($payload, $id) : MigrationOutcome::current($id));
        $this->app->instance(RecordReconciler::class, $reconciler);
        $definition = (new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry))->resolve(self::STEM);
        $base = app(StandaloneRecordQuery::class)->query()->where('id', '!=', 'private');
        $scoped = $this->reader->publicScope($base, $definition);
        $this->assertSame(['public'], $scoped->pluck('id')->all());
        $this->assertDatabaseHas(Beam::table('particles'), ['id' => 'transition', 'migration_status' => 'pending']);
        $this->assertSame(1, $scoped->count());
    }

    public function test_backing_uses_declared_address_filters_and_default_order(): void
    {
        $this->register();
        $this->insert('a');
        $this->insert('z');
        $this->insert('other', ref: self::STEM.'/other/1');
        $request = Request::create('/?collection='.urlencode(self::STEM));
        $backing = new CollectionBacking($request, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry), app(StandaloneRecordQuery::class));
        $this->assertSame(['z', 'a'], $backing->query([])->pluck('id')->all());
        $this->assertSame(['a'], $backing->query(['title' => 'a'])->pluck('id')->all());
        $this->assertSame(['title'], $backing->filterVocabulary()->filterNames());
        $this->assertSame(['title'], $backing->filterVocabulary()->sortNames());
        $this->assertNull($backing->resolveForWrite('other', []));
        $this->assertSame(self::STEM.'/1', $backing->newRecord()->model()->schema_ref);
    }

    public function test_backing_rejects_an_undeclared_filter(): void
    {
        $this->register();
        $request = Request::create('/?collection='.urlencode(self::STEM));
        $backing = new CollectionBacking($request, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry), app(StandaloneRecordQuery::class));
        $this->expectException(ValidationException::class);
        $backing->query(['secret' => 'guess']);
    }

    public function test_both_transport_writes_use_the_latest_schema_and_keep_guest_writes_refused(): void
    {
        $this->register();
        $this->app->instance(SchemaRegistry::class, $this->registry);
        $this->app->instance(SchemaTargetResolver::class, new RegistrySchemaTargetResolver($this->registry));
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        CollectionRoutes::mount();
        $query = '?collection='.urlencode(self::STEM);
        $body = ['fields' => ['title' => 'First', 'visible' => true]];
        $this->postJson('/records'.$query, $body)->assertForbidden();
        $this->postJson('/frame/resources/records'.$query, $body)->assertForbidden();
        foreach (['create', 'update'] as $ability) {
            Gate::define($ability, fn (?User $actor) => $actor !== null);
        }
        $this->actingAs((new User)->forceFill(['id' => 1]));
        $this->register(version: 2);
        $rest = $this->postJson('/records'.$query, $body)->assertCreated()->json('data');
        $frame = $this->postJson('/frame/resources/records'.$query, $body)->assertSuccessful()->json('data');
        $this->assertSame('First', $rest['fields']['title']);
        $this->assertSame('First', $frame['fields']['title']);
        $this->assertSame(self::STEM.'/2', $rest['schema']['$id']);
        $this->assertSame(self::STEM.'/2', $frame['schema']['$id']);
        $this->assertSame([self::STEM.'/2'], DB::table(Beam::table('particles'))->distinct()->pluck('schema_ref')->all());
        $this->assertDatabaseCount(Beam::table('particles'), 2);
        $this->postJson('/records'.$query, ['fields' => ['title' => 123]])->assertUnprocessable();
        $this->postJson('/frame/resources/records'.$query, ['fields' => ['title' => 123]])->assertUnprocessable();
        $this->assertDatabaseCount(Beam::table('particles'), 2);
    }

    public function test_guest_cannot_probe_hidden_fields_through_filters_sorts_or_panel(): void
    {
        $this->register();
        $schema = (new RegistrySchemaTargetResolver($this->registry))->targetFor(self::STEM);
        $schema['$id'] = self::STEM.'/2';
        $schema['properties']['title']['x-sort']['default'] = false;
        $schema['properties']['secret']['x-filter'] = ['name' => 'hidden', 'operator' => 'exact', 'control' => 'text'];
        $schema['properties']['secret']['x-sort'] = ['name' => 'hidden', 'default' => true, 'direction' => 'asc'];
        $this->registry->register($schema);
        foreach (['a' => 'zeta', 'z' => 'alpha'] as $id => $secret) {
            $this->insert($id);
            DB::table(Beam::table('particles'))->where('id', $id)->update(['payload' => json_encode(['title' => $id, 'secret' => $secret, 'visible' => true])]);
        }
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        CollectionRoutes::mount();
        $query = '?collection='.urlencode(self::STEM);
        foreach (['/records', '/frame/resources/records'] as $uri) {
            foreach (['&filter[hidden]=alpha', '&filter[hidden]=gamma', '&sort=hidden', '&sort=-hidden'] as $probe) {
                $this->getJson($uri.$query.$probe)->assertUnprocessable();
            }
            $this->getJson($uri.$query.'&filter[title]=a&sort=title')->assertOk()->assertJsonPath('data.0.fields.title', 'a');
            // A private default sort is also an oracle: use the ordinary deterministic fallback.
            $this->getJson($uri.$query)->assertOk()->assertJsonPath('data.0.fields.title', 'a');
        }
        $panel = $this->getJson('/frame/resources/records/filters/schema'.$query)->assertOk()->json('data.properties');
        $this->assertArrayHasKey('title', $panel);
        $this->assertArrayNotHasKey('hidden', $panel);
        $this->actingAs((new User)->forceFill(['id' => 1]));
        foreach (['/records', '/frame/resources/records'] as $uri) {
            $this->getJson($uri.$query.'&filter[hidden]=alpha&sort=-hidden')->assertOk()->assertJsonPath('data.0.fields.title', 'z');
        }
        $this->assertArrayHasKey('hidden', $this->getJson('/frame/resources/records/filters/schema'.$query)->assertOk()->json('data.properties'));
    }

    public function test_malformed_stored_relations_are_absent_on_both_transports(): void
    {
        $this->register();
        $this->insert('source');
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        CollectionRoutes::mount();
        foreach ([null, 42, ['bad' => 'shape'], [null, 42, ['nested'], 'missing']] as $value) {
            DB::table(Beam::table('particles'))->where('id', 'source')->update(['payload' => json_encode(['title' => 'source', 'visible' => true, 'related' => $value])]);
            foreach (['/records', '/frame/resources/records'] as $uri) {
                $this->getJson($uri.'?collection='.urlencode(self::STEM))->assertOk()->assertJsonPath('data.0.relations.related', []);
            }
        }
    }

    public function test_guest_index_counts_and_pages_one_and_ten_thousand_records(): void
    {
        $this->register();
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        CollectionRoutes::mount();
        foreach ([1000, 10000] as $size) {
            DB::table(Beam::table('particles'))->delete();
            foreach (array_chunk(range(1, $size), 200) as $chunk) {
                $rows = [];
                foreach ($chunk as $i) {
                    $rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i),
                        'schema_ref' => self::STEM.'/1', 'schema_id' => self::STEM.'/1',
                        'source_tier' => 'local', 'migration_status' => 'current',
                        'payload' => json_encode(['title' => (string) $i, 'visible' => $i % 2 === 0, 'secret' => 'private']),
                        'created_at' => now(), 'updated_at' => now()];
                }
                DB::table(Beam::table('particles'))->insert($rows);
            }
            $start = hrtime(true);
            $response = $this->getJson('/records?collection='.urlencode(self::STEM).'&perPage=10')->assertOk();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $response->assertJsonPath('total', $size / 2);
            foreach ($response->json('data') as $record) {
                $this->assertArrayNotHasKey('secret', $record['fields']);
                $this->assertSame(0, (int) $record['fields']['title'] % 2);
            }
            fwrite(STDERR, sprintf("EP34 guest REST index rows=%d elapsed_ms=%.3f memory_peak_bytes=%d backend=sqlite current_reconciler_stub=true\n", $size, $elapsed, memory_get_peak_usage(true)));
        }
    }

    public function test_transport_relations_batch_targets_and_hide_private_and_missing_identically(): void
    {
        $this->register();
        $targetStem = 'https://example.test/targets';
        $targetSchema = ['$id' => $targetStem.'/1', 'type' => 'object',
            'x-beam-collection' => ['label' => 'Targets', 'nameField' => 'title'],
            'properties' => ['title' => ['type' => 'string'], 'visible' => ['type' => 'boolean'], 'secret' => ['type' => 'string']],
            'x-beam-public-read' => ['fields' => ['title'], 'where' => ['all' => [['field' => 'visible', 'operator' => 'equals', 'value' => true]]]]];
        $this->registry->register($targetSchema);
        $sourceSchema = (new RegistrySchemaTargetResolver($this->registry))->targetFor(self::STEM);
        $sourceSchema['$id'] = self::STEM.'/2';
        $sourceSchema['properties']['related']['x-beam-relation']['collection'] = $targetStem;
        $this->registry->register($sourceSchema);
        $this->insert('target', ref: $targetStem.'/1');
        $this->insert('private', false, ref: $targetStem.'/1');
        foreach (range(1, 12) as $i) {
            $sourceId = sprintf('11111111-1111-4111-8111-%012d', $i);
            $this->insert($sourceId);
            DB::table(Beam::table('particles'))->where('id', $sourceId)->update(['payload' => json_encode(['title' => 'source-'.$i, 'visible' => true, 'related' => ['target', 'private', 'missing']])]);
        }
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        CollectionRoutes::mount();
        $query = '?collection='.urlencode(self::STEM);
        foreach (['/records', '/frame/resources/records'] as $uri) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $rows = $this->getJson($uri.$query)->assertOk()->json('data');
            $targetQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], Beam::table('particles')) && in_array($targetStem.'/1', $q['bindings'], true));
            DB::disableQueryLog();
            $this->assertCount(1, $targetQueries);
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) {
                $this->assertSame(['target'], array_column($row['relations']['related'], 'id'));
                $this->assertSame(['title' => 'target'], $row['relations']['related'][0]['fields']);
            }
        }
        foreach (['/records/11111111-1111-4111-8111-000000000001', '/frame/resources/records/records/11111111-1111-4111-8111-000000000001'] as $uri) {
            $this->getJson($uri.$query)->assertOk()->assertJsonCount(1, 'data.relations.related');
        }
        $sourceSchema['$id'] = self::STEM.'/3';
        $sourceSchema['properties']['related']['x-beam-relation']['collection'] = 'https://example.test/unknown';
        $this->registry->register($sourceSchema);
        $this->getJson('/records'.$query)->assertOk()->assertJsonPath('data.0.relations.related', []);
    }

    public function test_registered_version_drain_visits_current_and_pending_local_records_only(): void
    {
        $this->register();
        $this->register(version: 2);
        $this->insert('current');
        $this->insert('pending', status: 'pending');
        $this->insert('shadow', tier: 'shadow');
        $this->insert('other', ref: 'https://example.test/other/1');
        $reconciler = Mockery::mock(RecordReconciler::class);
        $reconciler->shouldReceive('reconcile')->andReturnUsing(fn ($payload, $id) => MigrationOutcome::current($id));
        $reconciler->shouldReceive('reconcileEager')->twice()->withArgs(fn ($payload, $id, $stem, $llm) => in_array($payload['title'], ['current', 'pending'], true) && $stem === self::STEM && $llm === false)
            ->andReturnUsing(fn ($payload) => MigrationOutcome::migrated($payload, self::STEM.'/2'));
        $this->app->instance(RecordReconciler::class, $reconciler);
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        $this->assertSame(2, app(CollectionMigrationDrain::class)->run(self::STEM));
        $this->assertSame(2, DB::table(Beam::table('particles'))->where('schema_id', self::STEM.'/2')->count());
        $this->assertSame(self::STEM.'/1', DB::table(Beam::table('particles'))->where('id', 'shadow')->value('schema_id'));
    }

    public function test_frame_filter_panel_and_list_follow_the_addressed_latest_declaration(): void
    {
        $this->register();
        $this->insert('a');
        $this->insert('z');
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        $query = '?collection='.urlencode(self::STEM);
        $this->getJson('/frame/resources/records/filters/schema'.$query)->assertOk()
            ->assertJsonPath('data.properties.title.x-filter.operator', 'exact');
        $this->getJson('/frame/resources/records'.$query)->assertOk()->assertJsonPath('data.0.fields.title', 'z');
        $this->getJson('/frame/resources/records'.$query.'&filter[title]=a')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fields.title', 'a');
        $schema = (new RegistrySchemaTargetResolver($this->registry))->targetFor(self::STEM);
        $schema['$id'] = self::STEM.'/2';
        $schema['properties']['title']['x-sort']['direction'] = 'asc';
        $schema['properties']['title']['x-filter']['name'] = 'heading';
        $this->registry->register($schema);
        $this->getJson('/frame/resources/records/filters/schema'.$query)->assertOk()->assertJsonPath('data.properties.heading.x-filter.operator', 'exact');
        $this->getJson('/frame/resources/records'.$query)->assertOk()->assertJsonPath('data.0.fields.title', 'a');
    }

    public function test_the_declared_address_is_required_on_every_runtime_read_surface(): void
    {
        CollectionRoutes::mount();
        foreach (['/records', '/records/11111111-1111-4111-8111-111111111111',
            '/frame/resources/records', '/frame/resources/records/schema',
            '/frame/resources/records/filters/schema'] as $uri) {
            $this->getJson($uri)->assertUnprocessable()->assertJsonValidationErrors('collection');
        }
    }

    public function test_frame_form_schema_uses_the_addressed_collections_latest_schema(): void
    {
        $this->register();
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        $query = '?collection='.urlencode(self::STEM);
        $this->getJson('/frame/resources/records/schema'.$query)->assertOk()
            ->assertJsonPath('properties.fields.$id', self::STEM.'/1');
        $this->register(version: 2);
        $this->getJson('/frame/resources/records/schema'.$query)->assertOk()
            ->assertJsonPath('properties.fields.$id', self::STEM.'/2');
        $this->getJson('/frame/resources/records/schema')->assertUnprocessable();
    }

    public function test_one_declared_resource_serves_guest_records_over_rest_and_frame(): void
    {
        $this->register();
        $id = '11111111-1111-4111-8111-111111111111';
        $this->insert($id);
        $this->app->instance(CollectionSchemaResolver::class, new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry));
        CollectionRoutes::mount();
        $query = '?collection='.urlencode(self::STEM);
        $rest = $this->getJson('/records/'.$id.$query)->assertOk()->json('data');
        $frame = $this->getJson('/frame/resources/records/records/'.$id.$query)->assertOk()->json('data');
        $this->assertSame($rest['fields'], $frame['fields']);
        $this->assertSame(['title' => $id], $rest['fields']);
        $this->assertSame(self::STEM.'/1', $rest['schema']['$id']);
        $this->getJson('/records'.$query)->assertOk()->assertJsonPath('data.0.fields.title', $id);
    }
}
