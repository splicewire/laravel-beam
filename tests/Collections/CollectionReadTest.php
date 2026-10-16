<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\MigrationOutcome;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Splicewire\Beam\Collections\CollectionBacking;
use Splicewire\Beam\Collections\CollectionRead;
use Splicewire\Beam\Collections\CollectionRelations;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;

class CollectionReadTest extends TestCase
{
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
            $t->timestamps();
        });
        $this->registry = new DatabaseSchemaRegistry;
        $reconciler = Mockery::mock(RecordReconciler::class);
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
        $this->expectException(InvalidFilterQuery::class);
        $backing->query(['secret' => 'guess']);
    }
}
