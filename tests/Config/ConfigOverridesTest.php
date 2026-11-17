<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Ops\Config\ConfigVersionsOp;
use Splicewire\Beam\Particle\Ops\Config\DiscardConfigDraftOp;
use Splicewire\Beam\Particle\Ops\Config\PublishConfigOp;
use Splicewire\Beam\Particle\Ops\Config\RestoreConfigVersionOp;
use Splicewire\Beam\Particle\Ops\Config\SaveConfigDraftOp;
use Splicewire\Beam\Tests\Fixtures\Config\ConfigOperatorActor;
use Splicewire\Beam\Tests\Fixtures\Config\MapGroupSection;
use Splicewire\Beam\Tests\Fixtures\Config\MixedGroupSection;
use Splicewire\Beam\Tests\Fixtures\Config\NearestChildSection;
use Splicewire\Beam\Tests\Fixtures\Config\NestedGroupSection;
use Splicewire\Beam\Tests\Fixtures\Config\PanelsSection;
use Splicewire\Beam\Tests\Fixtures\Config\ProtectedMapSection;
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

        // The host's config at THIS boot (the next-boot probes set it before booting a second application).
        foreach (self::$bootConfig as $root => $value) {
            $app['config']->set($root, $value);
        }
    }

    /** @var array<string, mixed> root => the host's value at the next boot */
    private static array $bootConfig = [];

    /** Boot the next application (a deploy, a worker restart) with the host's `$config`, then put this one back. */
    private function nextBoot(array $config, callable $assert): void
    {
        self::$bootConfig = $config;

        try {
            $assert($this->createApplication());
        } finally {
            self::$bootConfig = [];
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
        }
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
        // Another process publishes: the store and the shared token move, and this worker's repository has not re-synced.
        // (The publishing process applies its own publish at once, so the publish runs on a separate repository.)
        $worker = app('config');
        $this->app->instance('config', clone $worker);
        app(ConfigOverrideStore::class)->saveDraft('panels', [['pointer' => '/title', 'op' => 'set', 'value' => 'From the operator']]);
        app(ConfigOverrideStore::class)->publish('panels');
        $this->app->instance('config', $worker);
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

    /* ---- Nested children keep their own ceiling and secrecy (Standards HIGH-1/HIGH-2, 2026-11-15) ----
     * The three test_qa_ cases are build-qa's OperatorBoundaryQaTest repro, adopted as regressions. */

    /** @return array{deploy: string, token: string, label: string} the host's `qa.group.group` object */
    private function nestedGroup(): array
    {
        $group = ['deploy' => 'host-driver', 'token' => 'fake-original', 'label' => 'original'];
        config(['qa.group' => ['group' => $group]]);
        app(ConfigSectionRegistry::class)->section(NestedGroupSection::class, owner: 'qa/fixture');

        return $group;
    }

    private function nestedRaw(): string
    {
        return json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('config_scopes'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
        ]);
    }

    /** build-qa: a parent-object set may not change a host-only child. */
    public function test_qa_parent_object_cannot_write_a_host_only_child(): void
    {
        $group = $this->nestedGroup();
        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group/deploy', 'op' => 'set', 'value' => '"changed-driver"'])->assertStatus(422);

        $group['deploy'] = 'changed-driver';
        $refused = $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'set', 'value' => json_encode($group)])->assertStatus(422);
        $this->assertStringContainsString('/group/deploy', (string) $refused->json('message'), 'the refusal names the child pointer');
    }

    public function test_a_parent_object_may_resend_its_host_only_children_unchanged(): void
    {
        $group = $this->nestedGroup();
        $group['label'] = 'from the operator';

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'set', 'value' => json_encode($group)])->assertOk();
        $this->op('publish', ['root' => 'qa.group'])->assertOk();

        $this->assertSame('from the operator', config('qa.group.group.label'));
        $this->assertSame('host-driver', config('qa.group.group.deploy'), 'the host-only child is value-equal, so allowed and unchanged');
    }

    public function test_a_parent_null_that_would_clear_a_host_only_child_is_refused(): void
    {
        $this->nestedGroup();

        $refused = $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'null'])->assertStatus(422);
        $this->assertStringContainsString('/group/deploy', (string) $refused->json('message'));
    }

    /** build-qa: a declared secret inside a parent-object set never reaches a plain override, a scope or a version. */
    public function test_qa_parent_object_does_not_store_secret_children_in_plaintext(): void
    {
        $group = $this->nestedGroup();
        $group['token'] = 'sk-FAKE-QA-nested-secret';

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'set', 'value' => json_encode($group)])->assertOk();
        $this->assertStringNotContainsString($group['token'], $this->nestedRaw(), 'a nested declared secret must never enter plain overrides, scopes or version snapshots');

        $this->op('publish', ['root' => 'qa.group'])->assertOk();
        $this->assertSame('sk-FAKE-QA-nested-secret', config('qa.group.group.token'), 'the running configuration holds it, decrypted');
        $this->assertSame('original', config('qa.group.group.label'));

        // A restore is a new draft built from a version snapshot: it must not resurrect a plaintext copy either.
        $versions = app(ConfigOverrideStore::class)->versionsOf('qa.group');
        app(ConfigOverrideStore::class)->restore('qa.group', end($versions)->id);
        $this->assertStringNotContainsString($group['token'], $this->nestedRaw(), 'still absent after a restore');

        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get('qa.group'));
        $this->assertStringNotContainsString($group['token'], json_encode($row), 'reads and provenance redact it');
    }

    public function test_the_parent_s_next_op_replaces_the_secret_child_it_split_out(): void
    {
        $group = $this->nestedGroup();
        $group['token'] = 'sk-FAKE-first';
        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'set', 'value' => json_encode($group)])->assertOk();

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'unset'])->assertOk();
        $this->op('publish', ['root' => 'qa.group'])->assertOk();

        $this->assertSame('fake-original', config('qa.group.group.token'), 'unsetting the parent drops its secret child too');
        $this->assertArrayNotHasKey('/group/token', app(ConfigOverrideStore::class)->scope('qa.group')->draft ?? []);
    }

    /**
     * build-qa (corroborating Spec HIGH-2): a publish whose cache write fails, then a recovered cache, never leaves the
     * worker at the old published version. Adapted: the token is now PUT, not incremented, so the outage fails writes.
     */
    public function test_qa_worker_sees_publish_after_counter_write_failure_and_recovery(): void
    {
        $flaky = new WriteRefusingArrayStore;
        Cache::extend('qa-flaky', fn () => new \Illuminate\Cache\Repository($flaky));
        config(['cache.stores.qa-flaky' => ['driver' => 'qa-flaky'], 'beam.core.config_overrides.cache_store' => 'qa-flaky']);
        $this->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => '"One"'])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'panels'])->assertOk();
        $this->assertSame('One', config('panels.title'));

        $flaky->failWrites = true;
        app(ConfigOverrideStore::class)->saveDraft('panels', [['pointer' => '/title', 'op' => 'set', 'value' => 'Two']]);
        app(ConfigOverrideStore::class)->publish('panels');
        $flaky->failWrites = false;
        app(ConfigOverlay::class)->sync();

        $this->assertSame('Two', config('panels.title'), 'a recovered cache must not leave a long-lived worker at the old published version');
    }

    /** Root ruling (A), row 64e24681: a publish whose cache write fails says so, with the bound, in the response and the log. */
    public function test_a_publish_whose_cache_write_fails_tells_the_operator_the_propagation_bound(): void
    {
        $flaky = new WriteRefusingArrayStore;
        Cache::extend('qa-flaky', fn () => new \Illuminate\Cache\Repository($flaky));
        config(['cache.stores.qa-flaky' => ['driver' => 'qa-flaky'], 'beam.core.config_overrides.cache_store' => 'qa-flaky', 'beam.core.config_overrides.recheck_seconds' => 45]);
        $expected = 'published; propagation to other processes may take up to 45 s (cache write failed)';

        $this->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => '"One"'])->assertOk();
        $this->assertNull($this->postJson('/beam-config/publish', ['root' => 'panels'])->assertOk()->json('data.notice'), 'a healthy publish has nothing to tell');

        Log::spy();
        $flaky->failWrites = true;
        $this->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => '"Two"'])->assertOk();
        $response = $this->postJson('/beam-config/publish', ['root' => 'panels'])->assertOk();

        $this->assertSame($expected, $response->json('data.notice'));
        $this->assertSame('Two', config('panels.title'), 'the publishing process still applied it at once');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === $expected && $context['root'] === 'panels')->once();
    }

    /** build-qa's parent write: the whole `/group` object, its host-only child resent value-equal, a fake secret inside. */
    private function qaParentDraft(): void
    {
        $this->postJson('/beam-config/save-draft', [
            'root' => 'qa.group', 'pointer' => '/group', 'op' => 'set',
            'value' => json_encode(['deploy' => 'host-driver', 'token' => 'sk-FAKE-parent', 'label' => 'Operator label']),
        ])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.group'])->assertOk();
        $this->assertSame('sk-FAKE-parent', config('qa.group.group.token'));
    }

    /**
     * build-qa (ParentEditAfterSplitQaTest, 2026-11-16; credited): unsetting the secret split out of a parent write
     * inherits the lower tier, and the parent's own change stays published. The parent INHERITS the split leaf.
     */
    public function test_qa_unsetting_a_split_secret_child_inherits_the_lower_tier(): void
    {
        $this->nestedGroup();
        $this->qaParentDraft();

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group/token', 'op' => 'unset'])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.group'])->assertOk();

        $this->assertSame('fake-original', config('qa.group.group.token'), 'unset must inherit the host secret, even after a compound parent write');
        $this->assertSame('Operator label', config('qa.group.group.label'), 'the independent parent change stays published');
        $this->assertSame(['/deploy', '/token'], app(ConfigOverrideStore::class)->scope('qa.group')->draft['/group']['inherit'] ?? null);
    }

    /**
     * build-qa (ParentEditAfterSplitQaTest, 2026-11-16; credited): a host-only child resent value-equal inside a parent
     * write is not pinned: after a deploy changes the host value, the next boot reads the new one.
     */
    public function test_qa_equal_host_child_resent_in_a_parent_does_not_pin_the_old_host_value_at_next_boot(): void
    {
        $this->nestedGroup();
        $this->qaParentDraft();
        $this->assertArrayNotHasKey('deploy', app(ConfigOverrideStore::class)->scope('qa.group')->draft['/group']['value'] ?? [], 'the stored object does not carry the host-only child');

        $this->nextBoot(['qa.group' => ['group' => ['deploy' => 'host-driver-after-deploy', 'token' => 'fake-original', 'label' => 'original']]], function ($next): void {
            $this->assertSame('host-driver-after-deploy', $next['config']->get('qa.group.group.deploy'), 'the host-only child still belongs to the host after a deploy');
            $this->assertSame('Operator label', $next['config']->get('qa.group.group.label'), 'the operator\'s own change still applies');
        });
    }

    /**
     * build-qa (ProtectedMapInheritanceQaTest, 2026-11-16; credited): a HOST-ONLY map resent unchanged inside a parent
     * write is inherited whole, so an entry the host adds later still arrives at the next boot. Predicted from the
     * source to fail at 2003850 (leaf-level inherit) and fixed before it ran there.
     */
    public function test_qa_parent_keeps_a_new_host_owned_map_entry_at_next_boot(): void
    {
        config(['qa.protected-map' => ['group' => ['deployment' => ['a' => 'A'], 'label' => 'host label']]]);
        app(ConfigSectionRegistry::class)->section(ProtectedMapSection::class, owner: 'qa/fixture');

        $this->postJson('/beam-config/save-draft', [
            'root' => 'qa.protected-map', 'pointer' => '/group', 'op' => 'set',
            'value' => json_encode(['deployment' => ['a' => 'A'], 'label' => 'Operator label']),
        ])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.protected-map'])->assertOk();
        $this->assertSame(['a' => 'A'], config('qa.protected-map.group.deployment'));
        $this->assertSame(['/deployment'], app(ConfigOverrideStore::class)->scope('qa.protected-map')->draft['/group']['inherit'] ?? null, 'the map is inherited at its own pointer, not leaf by leaf');

        $this->nextBoot(['qa.protected-map' => ['group' => ['deployment' => ['a' => 'A', 'b' => 'B'], 'label' => 'host label']]], function ($next): void {
            $this->assertSame(['a' => 'A', 'b' => 'B'], $next['config']->get('qa.protected-map.group.deployment'), 'the whole deployment map is Host-only; a parent operator write must not delete a new Host entry');
            $this->assertSame('Operator label', $next['config']->get('qa.protected-map.group.label'));
        });
    }

    /**
     * build-qa (NearestChildOwnershipQaTest, 2026-11-16; credited): the inverse boundary. Inside a HOST-ONLY record, an
     * explicitly operator-owned child (nearest declaration wins) keeps the change a parent write makes to it, while its
     * host-only sibling stays inherited. Predicted from the source to fail at 5b25977 (the whole host record was
     * inherited) and fixed before it ran there.
     */
    public function test_qa_parent_set_does_not_silently_drop_an_explicit_operator_child_under_a_host_object(): void
    {
        config(['qa.nearest-child' => ['group' => ['limits' => ['hard' => 10, 'soft' => 5]]]]);
        app(ConfigSectionRegistry::class)->section(NearestChildSection::class, owner: 'qa/fixture');
        $this->assertSame(ConfigTier::Operator, NearestChildSection::keyDeclaration(['group', 'limits', 'soft'])->ceiling, 'the nearest child declaration is the authority');

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.nearest-child', 'pointer' => '/group/limits/soft', 'op' => 'set', 'value' => '7'])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.nearest-child'])->assertOk();
        $this->assertSame(7, config('qa.nearest-child.group.limits.soft'), 'the direct child write is admitted');

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.nearest-child', 'pointer' => '/group/limits/soft', 'op' => 'unset'])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.nearest-child'])->assertOk();
        $this->assertSame(5, config('qa.nearest-child.group.limits.soft'));

        $this->postJson('/beam-config/save-draft', [
            'root' => 'qa.nearest-child', 'pointer' => '/group', 'op' => 'set', 'value' => json_encode(['limits' => ['hard' => 10, 'soft' => 7]]),
        ])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.nearest-child'])->assertOk();

        $this->assertSame(7, config('qa.nearest-child.group.limits.soft'), 'an accepted parent write must not silently discard the explicitly Operator-owned child');
        $this->assertSame(10, config('qa.nearest-child.group.limits.hard'), 'the Host sibling stays inherited');
        $this->assertSame(['/limits/hard'], app(ConfigOverrideStore::class)->scope('qa.nearest-child')->draft['/group']['inherit'] ?? null, 'the host record is walked into, not inherited whole');
    }

    /**
     * The boundary the other way: inside an operator-owned object, the operator's map is still REPLACED (an entry it
     * leaves out is dropped) and its record's own key changes, while the one host-only key nested in that record is
     * inherited at its OWN pointer, never at the record or the parent.
     */
    public function test_a_host_key_nested_in_an_operator_record_is_inherited_alone(): void
    {
        $host = ['routes' => ['a' => 'A', 'b' => 'B'], 'limits' => ['hard' => 10, 'soft' => 5]];
        config(['qa.mixed' => ['group' => $host]]);
        app(ConfigSectionRegistry::class)->section(MixedGroupSection::class, owner: 'qa/fixture');

        $this->postJson('/beam-config/save-draft', [
            'root' => 'qa.mixed', 'pointer' => '/group', 'op' => 'set',
            'value' => json_encode(['routes' => ['a' => 'A2'], 'limits' => ['hard' => 10, 'soft' => 7]]),
        ])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.mixed'])->assertOk();

        $this->assertSame(['a' => 'A2'], config('qa.mixed.group.routes'), 'the operator-owned map is replaced: b is dropped');
        // Key order is not part of the contract: an inherited key is filled in after the keys the parent owns.
        $limits = config('qa.mixed.group.limits');
        ksort($limits);
        $this->assertSame(['hard' => 10, 'soft' => 7], $limits);
        $this->assertSame(['/limits/hard'], app(ConfigOverrideStore::class)->scope('qa.mixed')->draft['/group']['inherit'] ?? null);

        $this->nextBoot(['qa.mixed' => ['group' => ['routes' => ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'limits' => ['hard' => 20, 'soft' => 5]]]], function ($next): void {
            $this->assertSame(20, $next['config']->get('qa.mixed.group.limits.hard'), 'the nested host-only key follows the host');
            $this->assertSame(7, $next['config']->get('qa.mixed.group.limits.soft'), 'the operator still owns its sibling');
            $this->assertSame(['a' => 'A2'], $next['config']->get('qa.mixed.group.routes'), 'a new host entry does not enter an operator-owned map');
        });
    }

    /** The inherit list keeps REPLACE semantics for what a parent owns: a map `set` still drops an entry it leaves out. */
    public function test_a_map_parent_set_still_drops_an_entry_it_leaves_out(): void
    {
        config(['qa.maps' => ['routes' => ['a' => 'A', 'b' => 'B']]]);
        app(ConfigSectionRegistry::class)->section(MapGroupSection::class, owner: 'qa/fixture');

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.maps', 'pointer' => '/routes', 'op' => 'set', 'value' => json_encode(['a' => 'A2'])])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'qa.maps'])->assertOk();

        $this->assertSame(['a' => 'A2'], config('qa.maps.routes'), 'b is dropped, a is replaced');
        $this->assertArrayNotHasKey('inherit', app(ConfigOverrideStore::class)->scope('qa.maps')->draft['/routes']);
    }

    /* ---- The reader agrees with the runtime beneath a published parent (config-sections 07, review-r1: 05's fold read
     * exact pointers only, so every leaf under a published operator parent reported the base value and tier) ---- */

    /**
     * Every leaf `config()` holds for `$root` is a row with the same value (null for a declared secret), and every row the
     * fold reports ABSENT is absent at runtime.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertRowAgreesWithRuntime(array $row, string $root): void
    {
        $runtime = config($root);
        $keys = collect($row['keys'])->keyBy('pointer');

        foreach (\Splicewire\Beam\Config\JsonPointer::leaves($runtime) as $pointer) {
            $this->assertTrue($keys->has($pointer), "{$pointer} has a row");
            $key = $keys[$pointer];
            [, $value] = \Splicewire\Beam\Config\JsonPointer::get($runtime, $pointer);
            $this->assertSame($key['secret'] ? null : $value, $key['value'], "{$pointer}: the row reads what config() holds");
        }

        foreach ($keys as $pointer => $key) {
            if (collect($key['layers'])->last()['removed'] ?? false) {
                $this->assertFalse(\Splicewire\Beam\Config\JsonPointer::get($runtime, $pointer)[0], "{$pointer}: reported absent, and absent at runtime");
            }
        }
    }

    public function test_every_leaf_beneath_a_published_operator_parent_reads_what_the_runtime_holds(): void
    {
        $group = $this->nestedGroup();
        $group['label'] = 'from the operator';
        $group['token'] = 'fake-operator-token';

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.group', 'pointer' => '/group', 'op' => 'set', 'value' => json_encode($group)])->assertOk();
        $row = $this->op('publish', ['root' => 'qa.group'])->assertOk()->json('data');
        $keys = collect($row['keys'])->keyBy('pointer');

        $this->assertSame('from the operator', config('qa.group.group.label'));
        $this->assertSame('operator', $keys['/group/label']['tier'], 'an owned child is the ancestor entry\'s');
        $this->assertSame('from the operator', $keys['/group/label']['value']);
        $this->assertNotSame('operator', $keys['/group/deploy']['tier'], 'a host-only child the entry inherits keeps its own tier');
        $this->assertSame('host-driver', $keys['/group/deploy']['value']);
        $this->assertSame('operator', $keys['/group/token']['tier'], 'a split secret is its own operator entry');
        $this->assertNull($keys['/group/token']['value']);
        $this->assertRowAgreesWithRuntime($row, 'qa.group');
    }

    public function test_a_map_parent_replace_reports_the_entry_it_leaves_out_as_absent_under_the_operator(): void
    {
        config(['qa.maps' => ['routes' => ['a' => 'A', 'b' => 'B']]]);
        app(ConfigSectionRegistry::class)->section(MapGroupSection::class, owner: 'qa/fixture');

        $this->postJson('/beam-config/save-draft', ['root' => 'qa.maps', 'pointer' => '/routes', 'op' => 'set', 'value' => json_encode(['a' => 'A2'])])->assertOk();
        $row = $this->postJson('/beam-config/publish', ['root' => 'qa.maps'])->assertOk()->json('data');
        $keys = collect($row['keys'])->keyBy('pointer');

        $this->assertSame(['tier' => 'operator', 'value' => 'A2'], ['tier' => $keys['/routes/a']['tier'], 'value' => $keys['/routes/a']['value']]);
        $this->assertSame('operator', $keys['/routes/b']['tier'], "b's absence is the operator's replace, not the host's value");
        $this->assertNull($keys['/routes/b']['value']);
        $this->assertTrue(collect($keys['/routes/b']['layers'])->last()['removed']);
        $this->assertRowAgreesWithRuntime($row, 'qa.maps');
    }

    /**
     * review-r1 (ruling (A) follow-on): a store that reports a failed write by returning false, not by throwing (Laravel's
     * `Repository::forever()`, Memcached), is a failed write: the notice, the warning, and the recheck catches it up.
     */
    public function test_a_cache_write_the_store_refuses_with_false_is_a_failed_write(): void
    {
        $store = new WriteReturningFalseArrayStore;
        Cache::extend('qa-false', fn () => new \Illuminate\Cache\Repository($store));
        config(['cache.stores.qa-false' => ['driver' => 'qa-false'], 'beam.core.config_overrides.cache_store' => 'qa-false', 'beam.core.config_overrides.recheck_seconds' => 0]);
        $expected = 'published; propagation to other processes may take up to 0 s (cache write failed)';

        $this->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => '"One"'])->assertOk();
        $this->postJson('/beam-config/publish', ['root' => 'panels'])->assertOk();
        $worker = clone app('config');
        $warm = new ConfigOverlay($this->app, $this->app->make(\Splicewire\Beam\Config\Overrides\ConfigOverrideCounter::class));
        $base = app('config');
        $this->app->instance('config', $worker);
        $warm->sync();
        $this->assertSame('One', config('panels.title'));
        $this->app->instance('config', $base);

        Log::spy();
        $store->refuseWrites = true;
        $this->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => '"Two"'])->assertOk();
        $response = $this->postJson('/beam-config/publish', ['root' => 'panels'])->assertOk();

        $this->assertSame($expected, $response->json('data.notice'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === $expected && $context['root'] === 'panels')->once();
        $this->assertNotSame(app(ConfigOverrideStore::class)->publishedToken(), Cache::store('qa-false')->get(\Splicewire\Beam\Config\Overrides\ConfigOverrideCounter::KEY), 'the refused write left the cache stale');

        // The recheck path (strict here) catches a warm process up despite the stale cache.
        $this->app->instance('config', $worker);
        $warm->sync();
        $this->assertSame('Two', config('panels.title'));
    }
}

/** A cache store that reads fine but reports a refused write by returning false, never throwing. */
final class WriteReturningFalseArrayStore extends \Illuminate\Cache\ArrayStore
{
    public bool $refuseWrites = false;

    public function put($key, $value, $seconds)
    {
        return $this->refuseWrites ? false : parent::put($key, $value, $seconds);
    }

    public function forever($key, $value)
    {
        return $this->refuseWrites ? false : parent::forever($key, $value);
    }
}

/** A cache store that reads fine but refuses writes while `$failWrites` is on (build-qa's transient outage). */
final class WriteRefusingArrayStore extends \Illuminate\Cache\ArrayStore
{
    public bool $failWrites = false;

    public function put($key, $value, $seconds)
    {
        if ($this->failWrites) {
            throw new \RuntimeException('fake transient cache outage during publish');
        }

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        if ($this->failWrites) {
            throw new \RuntimeException('fake transient cache outage during publish');
        }

        return parent::increment($key, $value);
    }
}
