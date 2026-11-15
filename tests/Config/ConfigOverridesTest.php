<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Ops\Config\ConfigVersionsOp;
use Splicewire\Beam\Particle\Ops\Config\DiscardConfigDraftOp;
use Splicewire\Beam\Particle\Ops\Config\PublishConfigOp;
use Splicewire\Beam\Particle\Ops\Config\RestoreConfigVersionOp;
use Splicewire\Beam\Particle\Ops\Config\SaveConfigDraftOp;
use Splicewire\Beam\Tests\Fixtures\Config\ConfigOperatorActor;
use Splicewire\Beam\Tests\Fixtures\Config\PanelsSection;
use Splicewire\Beam\Tests\Fixtures\Config\ReadsPanelsConfigJob;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections ticket 05: an operator override reaches the next request, job and command. Every behaviour is driven
 * through the `beam-config` ops, the path a person or an agent uses, and observed from outside: a probe route reads
 * `config()` in the NEXT request, a queued job reads it on its next run, and the one reader reports provenance. Nothing
 * here calls `config()->set` to fake an override.
 *
 * The store's tables come from the shipped migration stub itself, run on this suite's SQLite (the Postgres lane, with a
 * tenant schema, is {@see ConfigOverrideMigrationPostgresTest}).
 */
class ConfigOverridesTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    private static string $database;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        // A FILE database shared by the default connection and `central` (where the operator tier lives): two
        // `:memory:` connections would be two empty databases.
        $connection = ['driver' => 'sqlite', 'database' => self::$database, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.central', $connection);
        $app['config']->set('auth.providers.users.model', ConfigOperatorActor::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-overrides-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        Schema::create('config_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        ConfigOperatorActor::create(['name' => 'operator']);
        ConfigOperatorActor::create(['name' => 'member']);
        Gate::define('entitlement:os.operate', fn (?ConfigOperatorActor $actor): bool => $actor?->name === 'operator');

        config()->set('panels', array_merge(require self::FIXTURES.'/package/panels.php', require self::FIXTURES.'/host/panels.php'));
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(PanelsSection::class, owner: 'acme/panels', packageFile: realpath(self::FIXTURES.'/package/panels.php'));

        // Subject-free ops mount above the resource's {id} routes (NoSubject's warning).
        Particle::ops('beam-config', 'beam-config', [
            SaveConfigDraftOp::class, PublishConfigOp::class, DiscardConfigDraftOp::class,
            RestoreConfigVersionOp::class, ConfigVersionsOp::class,
        ]);
        Particle::mount('beam-config')->register();

        // The behaviour a later request observes: what `config()` holds when the request runs.
        Route::get('/_probe/panels', fn () => ['panels' => config('panels')]);

        $this->actingAs(ConfigOperatorActor::query()->where('name', 'operator')->firstOrFail());
        app(ConfigOverlay::class)->sync();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    private function op(string $name, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/beam-config/{$name}", $body);
    }

    private function draft(string $pointer, string $op, mixed $value = null): \Illuminate\Testing\TestResponse
    {
        return $this->op('save-draft', ['root' => 'panels', 'pointer' => $pointer, 'op' => $op] + ($op === 'set' ? ['value' => json_encode($value)] : []));
    }

    private function probe(): array
    {
        return $this->getJson('/_probe/panels')->assertOk()->json('panels');
    }

    private function key(array $row, string $pointer): array
    {
        return collect($row['keys'])->firstWhere('pointer', $pointer);
    }

    public function test_publishing_an_override_changes_the_very_next_request(): void
    {
        $this->assertFalse($this->probe()['enabled']);

        $this->draft('/enabled', 'set', true)->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();

        $this->assertTrue($this->probe()['enabled']);
    }

    public function test_a_draft_never_changes_behaviour_and_discard_leaves_no_trace_in_the_published_state(): void
    {
        $this->draft('/enabled', 'set', true)->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();
        $published = DB::connection('central')->table(Beam::table('config_scopes'))->value('published_version');

        $row = $this->draft('/title', 'set', 'Draft title')->assertOk()->json('data');
        $this->assertSame('Panels', $this->probe()['title'], 'a saved draft must not reach the next request');
        $this->assertTrue($row['pins']['draftPending']);

        $row = $this->op('discard-draft', ['root' => 'panels'])->assertOk()->json('data');
        $this->assertFalse($row['pins']['draftPending']);
        $this->assertSame($published, DB::connection('central')->table(Beam::table('config_scopes'))->value('published_version'));
        $this->assertSame(['/enabled'], array_column($row['overrides'], 'pointer'), 'the discarded pointer is gone from the draft');
        $this->assertSame('Panels', $this->probe()['title']);
        $this->assertTrue($this->probe()['enabled']);
    }

    public function test_the_set_op_writes_a_value_the_next_request_reads(): void
    {
        $this->draft('/title', 'set', 'Operator title')->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();

        $this->assertSame('Operator title', $this->probe()['title']);
    }

    public function test_the_unset_op_returns_the_key_to_the_host_value(): void
    {
        $this->draft('/enabled', 'set', true)->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();
        $this->assertTrue($this->probe()['enabled']);

        $row = $this->draft('/enabled', 'unset')->assertOk()->json('data');
        $this->assertSame([], $row['overrides'], 'unset removes the tier\'s entry for the pointer');
        $this->op('publish', ['root' => 'panels'])->assertOk();

        $this->assertFalse($this->probe()['enabled'], 'unset inherits the HOST value (false), not the package value (true)');
    }

    public function test_the_explicit_null_op_is_distinct_from_unset_where_the_schema_allows_null(): void
    {
        $this->draft('/banner', 'null')->assertOk();
        $row = $this->op('publish', ['root' => 'panels'])->assertOk()->json('data');
        $after = $this->probe();
        $this->assertArrayHasKey('banner', $after);
        $this->assertNull($after['banner'], 'an explicit null is a retained null override, not "no override"');
        $this->assertSame('operator', $this->key($row, '/banner')['tier']);
        $this->assertSame('null', collect($row['overrides'])->firstWhere('pointer', '/banner')['op']);

        $this->draft('/banner', 'unset')->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();
        $this->assertSame('Welcome', $this->probe()['banner'], 'unset of the null override inherits the package value again');
    }

    public function test_the_explicit_null_op_is_refused_where_the_schema_does_not_allow_null(): void
    {
        $this->draft('/title', 'null')->assertStatus(422)->assertJsonValidationErrors('ops');
        $this->assertSame('Panels', $this->probe()['title']);
    }

    public function test_writes_are_validated_against_the_section_schema_and_refused_outside_the_tier_ceiling(): void
    {
        $this->draft('/enabled', 'set', 'yes please')->assertStatus(422)->assertJsonValidationErrors('ops');
        $this->draft('/driver', 'set', 'redis')->assertStatus(422)->assertJsonValidationErrors('ops.0.pointer');
        $this->draft('/catalog/chart', 'set', 'Bar chart')->assertStatus(422)->assertJsonValidationErrors('ops.0.pointer');
        $this->draft('/catalog/map', 'set', 'Map')->assertOk();
        $this->assertSame(0, DB::connection('central')->table(Beam::table('config_overrides'))->where('pointer', '/driver')->count());
    }

    public function test_the_ops_are_gated_on_the_operator_ability(): void
    {
        $this->actingAs(ConfigOperatorActor::query()->where('name', 'member')->firstOrFail());
        $this->draft('/enabled', 'set', true)->assertForbidden();
        $this->op('publish', ['root' => 'panels'])->assertForbidden();
    }

    public function test_every_publish_is_a_version_and_restoring_makes_a_new_draft(): void
    {
        $this->draft('/title', 'set', 'One')->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();
        $this->draft('/title', 'set', 'Two')->assertOk();
        $this->op('publish', ['root' => 'panels'])->assertOk();
        $this->assertSame('Two', $this->probe()['title']);

        $versions = $this->op('versions', ['root' => 'panels'])->assertOk()->json('data.versions');
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertTrue($versions[0]['published'] && $versions[0]['draft']);

        $row = $this->op('restore', ['root' => 'panels', 'version' => 1])->assertOk()->json('data');
        $this->assertTrue($row['pins']['draftPending'], 'a restore is a draft');
        $this->assertSame(3, $row['pins']['draft']);
        $this->assertSame(2, $row['pins']['published']);
        $this->assertSame('Two', $this->probe()['title'], 'restoring never publishes');

        $this->op('publish', ['root' => 'panels'])->assertOk();
        $this->assertSame('One', $this->probe()['title']);
    }

    public function test_a_long_lived_worker_sees_the_change_on_its_next_job_after_the_counter_moves(): void
    {
        // Another process publishes: the store and the shared counter move, and this process has not re-synced yet.
        app(ConfigOverrideStore::class)->saveDraft('panels', [['pointer' => '/title', 'op' => 'set', 'value' => 'From the operator']]);
        app(ConfigOverrideStore::class)->publish('panels');
        $this->assertSame('Panels', config('panels.title'), 'the worker still runs on what it applied');

        ReadsPanelsConfigJob::$seen = null;
        app(Dispatcher::class)->dispatch(new ReadsPanelsConfigJob);

        $this->assertSame('From the operator', ReadsPanelsConfigJob::$seen, 'JobProcessing re-applied before the job ran');
    }

    public function test_provenance_reports_the_operator_tier_and_the_version_that_set_the_key(): void
    {
        $this->draft('/enabled', 'set', true)->assertOk();
        $row = $this->op('publish', ['root' => 'panels'])->assertOk()->json('data');
        $enabled = $this->key($row, '/enabled');

        $this->assertSame('operator', $enabled['tier']);
        $this->assertTrue($enabled['value']);
        $this->assertSame(['package', 'host', 'operator'], array_column($enabled['layers'], 'tier'));
        $this->assertSame('operator override · v1', end($enabled['layers'])['source']);
        $this->assertSame(['draft' => 1, 'published' => 1, 'draftPending' => false], $row['pins']);
        $this->assertTrue($row['values']['enabled'], 'the row\'s effective values are config()\'s');
    }

    public function test_a_secret_is_encrypted_at_rest_and_never_leaves_on_the_wire_or_in_a_version(): void
    {
        $plain = 'sk-fake-panels-0000';
        $this->draft('/api_key', 'set', $plain)->assertOk();
        $row = $this->op('publish', ['root' => 'panels'])->assertOk()->json('data');

        $this->assertSame($plain, config('panels.api_key'), 'the running configuration holds the value');
        $this->assertStringNotContainsString($plain, json_encode($row));
        $this->assertNull($this->key($row, '/api_key')['value']);
        $this->assertTrue($this->key($row, '/api_key')['set']);
        $override = collect($row['overrides'])->firstWhere('pointer', '/api_key');
        $this->assertTrue($override['secret'] && $override['set']);
        $this->assertNull($override['value']);
        $this->assertNotNull($override['setAt']);

        $raw = json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
            DB::connection('central')->table(Beam::table('config_scopes'))->get(),
        ]);
        $this->assertStringNotContainsString($plain, $raw, 'no table holds the plaintext');
        $this->assertStringNotContainsString($plain, json_encode($this->op('versions', ['root' => 'panels'])->json()));
    }
}
