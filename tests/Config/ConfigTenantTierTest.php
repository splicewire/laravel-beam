<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Events\TenantConfigLayerLaid;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Ops\Config\ConfigVersionsOp;
use Splicewire\Beam\Particle\Ops\Config\DiscardConfigDraftOp;
use Splicewire\Beam\Particle\Ops\Config\LockConfigKeyOp;
use Splicewire\Beam\Particle\Ops\Config\PublishConfigOp;
use Splicewire\Beam\Particle\Ops\Config\RestoreConfigVersionOp;
use Splicewire\Beam\Particle\Ops\Config\SaveConfigDraftOp;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tests\Fixtures\Config\ConfigOperatorActor;
use Splicewire\Beam\Tests\Fixtures\Config\EnterFakeTenant;
use Splicewire\Beam\Tests\Fixtures\Config\FakeTenancyContext;
use Splicewire\Beam\Tests\Fixtures\Config\TenantCatalogSection;
use Splicewire\Beam\Tests\Fixtures\Config\TenantPanelsSection;
use Splicewire\Beam\Tests\Fixtures\Config\UnreadableTenantStore;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections ticket 07, core half: the tenant tier. Driven through the `beam-config` ops in each realm and observed
 * from outside (a probe route reads `config()` in the next request), on three SQLite files standing in for `central` and
 * two tenants' own databases. Core's tenancy port is answered by {@see FakeTenancyContext}; beam-tenancy proves the same
 * on stancl's real bootstrappers, on schema, pooled and isolated tenants, and across two tenants' jobs on one worker.
 */
class ConfigTenantTierTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    /** `/group/settings` as central holds it (the package file): a parent write resends it unchanged. */
    private const SETTINGS = ['label' => 'central', 'fixed' => 'operator fixed', 'tags' => ['x' => 'X']];

    /** @var array<string, string> connection => its SQLite file */
    private static array $files = [];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');

        foreach (self::$files as $connection => $file) {
            $app['config']->set("database.connections.{$connection}", ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        }

        $app['config']->set('auth.providers.users.model', ConfigOperatorActor::class);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        foreach (['testing', 'central', 'tenant_a', 'tenant_b'] as $connection) {
            $stem = tempnam(sys_get_temp_dir(), "beam-cs07-{$connection}-");
            @unlink($stem);
            self::$files[$connection] = $stem.'.sqlite';
            touch(self::$files[$connection]);
        }

        parent::setUp();

        // The shared stubs on central and in each tenant's database, as the host's two migrate passes run them.
        foreach (['central', 'tenant_a', 'tenant_b'] as $connection) {
            $default = DB::getDefaultConnection();
            DB::setDefaultConnection($connection);

            try {
                foreach (['create_beam_versions_table', 'create_beam_config_overrides_table', 'create_beam_config_locks_table'] as $stub) {
                    (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
                }
            } finally {
                DB::setDefaultConnection($default);
            }
        }

        Schema::create('config_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        ConfigOperatorActor::create(['name' => 'operator']);
        ConfigOperatorActor::create(['name' => 'tenant-admin']);
        ConfigOperatorActor::create(['name' => 'member']);
        Gate::define('entitlement:os.operate', fn (?ConfigOperatorActor $actor): bool => $actor?->name === 'operator');
        Gate::define('tenant.config.operate', fn (?ConfigOperatorActor $actor): bool => $actor?->name === 'tenant-admin');

        $this->app->instance(FakeTenancyContext::class, new FakeTenancyContext);
        $this->app->instance(TenancyContext::class, app(FakeTenancyContext::class));

        config()->set('tpanels', require self::FIXTURES.'/package/tpanels.php');
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        foreach ([ConfigOverrideStore::class, ConfigOverlay::class, ConfigTenantOverlay::class] as $singleton) {
            $this->app->forgetInstance($singleton);
        }
        app(ConfigSectionRegistry::class)->section(TenantPanelsSection::class, owner: 'acme/tpanels', packageFile: realpath(self::FIXTURES.'/package/tpanels.php'));

        Particle::ops('beam-config', 'beam-config', [
            SaveConfigDraftOp::class, PublishConfigOp::class, DiscardConfigDraftOp::class,
            RestoreConfigVersionOp::class, ConfigVersionsOp::class, LockConfigKeyOp::class,
        ]);
        Particle::mount('beam-config')->register();

        // The host names who may use a workspace's tenant tier: a workspace admin.
        config()->set('beam.core.config_overrides.tenant_ability', 'tenant.config.operate');

        // A tenant hop runs after beam's prepended operator middleware, as a tenancy middleware does.
        $this->app->make(HttpKernel::class)->pushMiddleware(EnterFakeTenant::class);
        Route::get('/_probe/{root}', fn (string $root) => ['root' => config($root)]);

        app(ConfigOverlay::class)->sync();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (self::$files as $file) {
            @unlink($file);
        }
    }

    private function as(string $name): self
    {
        return $this->actingAs(ConfigOperatorActor::query()->where('name', $name)->firstOrFail());
    }

    /** @param  array<string, mixed>  $body */
    private function op(string $name, array $body, ?string $tenant = null): TestResponse
    {
        return $this->postJson("/beam-config/{$name}", $body, $tenant === null ? [] : ['X-Tenant' => $tenant]);
    }

    private function set(string $pointer, mixed $value, ?string $tenant = null): TestResponse
    {
        return $this->op('save-draft', ['root' => 'tpanels', 'pointer' => $pointer, 'op' => 'set', 'value' => json_encode($value)], $tenant);
    }

    private function publish(?string $tenant = null): TestResponse
    {
        return $this->op('publish', ['root' => 'tpanels'], $tenant);
    }

    /** @return array<string, mixed> */
    private function probe(?string $tenant = null, string $root = 'tpanels'): array
    {
        return $this->getJson("/_probe/{$root}", $tenant === null ? [] : ['X-Tenant' => $tenant])->assertOk()->json('root');
    }

    /** @param  array<string, mixed>  $row */
    private function key(array $row, string $pointer): ?array
    {
        return collect($row['keys'])->firstWhere('pointer', $pointer);
    }

    public function test_a_tenant_override_applies_inside_that_tenants_hop_and_nowhere_else(): void
    {
        $this->as('tenant-admin');
        $this->set('/review', true, 'a')->assertOk();
        $row = $this->publish('a')->assertOk()->json('data');

        $this->assertSame('tenant', $row['tier']);
        $this->assertTrue($this->probe('a')['review'], "tenant A's next request reads its override");
        $this->assertFalse($this->probe('b')['review'], 'tenant B never sees it');
        $this->assertFalse($this->probe()['review'], 'a central request never sees it');
        $this->assertFalse(config('tpanels.review'), 'the process holds the central value between hops');

        // The rows live in tenant A's own database; central and tenant B hold none.
        $this->assertSame(1, DB::connection('tenant_a')->table(Beam::table('config_scopes'))->count());
        $this->assertSame(0, DB::connection('central')->table(Beam::table('config_scopes'))->count());
        $this->assertSame(0, DB::connection('tenant_b')->table(Beam::table('config_scopes'))->count());
        $this->assertGreaterThan(0, DB::connection('tenant_a')->table(Beam::table('versions'))->count(), "the history is in A's workspace");
    }

    public function test_revert_restores_the_central_effective_value_including_the_operator_override(): void
    {
        $this->as('operator');
        $this->set('/theme', 'dark')->assertOk();
        $this->publish()->assertOk();

        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();

        $this->assertSame('pink', $this->probe('a')['theme']);
        $this->assertSame('dark', $this->probe('b')['theme'], "B inherits the operator's override");
        $this->assertSame('dark', $this->probe()['theme']);
        $this->assertSame('dark', config('tpanels.theme'), "after A's hop the process is back on the operator's value, not the package default");
    }

    public function test_the_tenant_row_offers_only_tenant_keys_with_the_inherited_value_and_its_provenance(): void
    {
        $this->as('operator');
        $this->set('/theme', 'dark')->assertOk();
        $this->publish()->assertOk();

        $this->as('tenant-admin');
        $row = $this->set('/review', true, 'a')->assertOk()->json('data');

        $this->assertSame('tenant', $row['tier']);
        $this->assertSame(
            ['/extras/k', '/extras/m', '/group/label', '/group/review', '/group/settings/label', '/group/settings/tags/x', '/review', '/routes/a', '/routes/b', '/theme'],
            collect($row['keys'])->pluck('pointer')->sort()->values()->all(),
            'operator-ceiling and host keys are not offered, at any depth (/limit, /driver, /group/deploy, /group/settings/fixed); '
            .'a tenant-owned child of an operator-owned record is (nearest declaration)',
        );
        $this->assertSame('operator', $this->key($row, '/theme')['tier'], 'the inherited value names the tier it came from');
        $this->assertSame('dark', $this->key($row, '/theme')['value']);
        $this->assertSame(['/review'], array_column($row['overrides'], 'pointer'), "the row's draft is the tenant's");
        $this->assertTrue($row['pins']['draftPending']);

        $published = $this->publish('a')->assertOk()->json('data');
        $this->assertSame('tenant', $this->key($published, '/review')['tier']);
        $this->assertSame(['package', 'tenant'], array_column($this->key($published, '/review')['layers'], 'tier'));

        // The operator realm's row is untouched by the tenant's draft.
        $this->as('operator');
        $central = $this->set('/theme', 'dark')->assertOk()->json('data');
        $this->assertSame('operator', $central['tier']);
        $this->assertNotNull($this->key($central, '/limit'));
    }

    public function test_a_write_above_the_tenant_ceiling_is_refused(): void
    {
        $this->as('tenant-admin');

        $this->set('/limit', 99, 'a')->assertStatus(422)->assertJsonFragment(['/limit has the ceiling operator; the tenant tier may not override it.']);
        $this->assertSame(10, $this->probe('a')['limit']);
    }

    public function test_an_operator_lock_refuses_the_tenant_write_with_its_reason_and_shows_locked(): void
    {
        $this->as('operator');
        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/review', 'reason' => 'Review is required on this install.'])->assertOk();

        $this->as('tenant-admin');
        $this->set('/review', true, 'a')->assertStatus(422)->assertJsonFragment(['/review is locked by the operator: Review is required on this install.']);

        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $review = $this->key($row, '/review');
        $this->assertSame('Review is required on this install.', $review['lock']['reason']);
        $this->assertFalse($review['overridable']);
        $this->assertTrue($this->key($row, '/theme')['overridable']);

        // A tenant can neither lock nor unlock.
        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/theme', 'reason' => 'mine'], 'a')->assertStatus(422);
        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/review', 'locked' => false], 'a')->assertStatus(422);

        // The lock is central data.
        $this->assertSame(1, DB::connection('central')->table(Beam::table('config_locks'))->count());
        $this->assertSame(0, DB::connection('tenant_a')->table(Beam::table('config_locks'))->count());
    }

    public function test_a_lock_takes_effect_over_an_override_the_tenant_published_before_it(): void
    {
        $this->as('tenant-admin');
        $this->set('/review', true, 'a')->assertOk();
        $this->publish('a')->assertOk();
        $this->assertTrue($this->probe('a')['review']);

        $this->as('operator');
        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/review', 'reason' => 'Fixed by the operator.'])->assertOk();
        $this->assertFalse($this->probe('a')['review'], 'the locked key falls back to the central value at once');

        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/review', 'locked' => false])->assertOk();
        $this->assertTrue($this->probe('a')['review'], 'unlocking gives the tenant its published override back');
    }

    public function test_the_lock_op_refuses_a_key_tenants_cannot_override_and_a_lock_without_a_reason(): void
    {
        $this->as('operator');

        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/limit', 'reason' => 'x'])->assertStatus(422);
        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/review', 'reason' => '  '])->assertStatus(422);
        $this->op('lock', ['root' => 'tpanels', 'pointer' => '/theme', 'locked' => false])->assertStatus(422);
    }

    public function test_a_member_without_the_tenant_ability_is_refused_and_neither_key_opens_the_other_tier(): void
    {
        $this->as('member');
        $this->set('/review', true, 'a')->assertForbidden();

        $this->as('operator');
        $this->set('/review', true, 'a')->assertForbidden();

        $this->as('tenant-admin');
        $this->set('/review', true)->assertForbidden();
    }

    /**
     * The read-policy change, named for the reviewers (lead 00:57Z): beam-config's declared read is core's
     * `beam.config-section.read`. Outside a tenant it answers exactly as the staff key did; inside one it answers the
     * host's `tenant_ability`, and a null ability lets nobody in.
     */
    public function test_the_read_policy_is_the_staff_key_outside_a_tenant_and_the_hosts_tenant_ability_inside(): void
    {
        // The actors live centrally: load them before entering a tenant.
        $actors = ConfigOperatorActor::query()->get()->keyBy('name');
        $read = fn (string $name): bool => Gate::forUser($actors[$name])->allows(ConfigSectionAccess::READ);
        $context = app(FakeTenancyContext::class);

        // Outside a tenant: unchanged from 02/05 (the staff key).
        $this->assertTrue($read('operator'));
        $this->assertFalse($read('tenant-admin'));
        $this->assertFalse($read('member'));

        // Inside a tenant: the host's tenant ability, and the staff key does not reach in.
        $context->enter('a');

        try {
            $this->assertTrue($read('tenant-admin'), 'a tenant admin holding the configured ability is allowed');
            $this->assertFalse($read('member'), 'a tenant user without it is denied');
            $this->assertFalse($read('operator'), "the operator's staff key does not open a tenant's tier");

            config()->set('beam.core.config_overrides.tenant_ability', null);
            $this->assertFalse($read('tenant-admin'), 'a null tenant ability denies everyone');
            $this->assertFalse($read('operator'));
            $this->assertFalse($read('member'));
        } finally {
            $context->leave();
        }

        $this->assertTrue($read('operator'), 'and outside a tenant the null tenant ability changes nothing');
    }

    public function test_with_no_tenant_ability_configured_no_tenant_user_reaches_the_tenant_tier(): void
    {
        config()->set('beam.core.config_overrides.tenant_ability', null);

        $this->as('tenant-admin');
        $this->set('/review', true, 'a')->assertForbidden();
        $this->assertSame(0, DB::connection('tenant_a')->table(Beam::table('config_scopes'))->count());
    }

    /**
     * 07 owns the ordering of the operator sync and the tenant tier: should the operator overlay re-sync while a tenant
     * layer is in the repository (two `JobProcessing` listeners in provider order), the operator's base must not capture
     * the tenant's value and the tenant's value must stay on top.
     */
    public function test_an_operator_sync_during_a_tenant_hop_keeps_the_tenant_on_top_and_out_of_the_operator_base(): void
    {
        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();

        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        app(ConfigTenantOverlay::class)->apply();
        $this->assertSame('pink', config('tpanels.theme'));

        // The operator publishes `theme` (a root the operator overlay has never touched) while A's layer is in place.
        $context->leave();
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->saveDraft('tpanels', [['pointer' => '/theme', 'op' => 'set', 'value' => 'dark']]);
        $context->enter('a');
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->publish('tpanels');
        app(ConfigOverlay::class)->recheckNow();
        app(ConfigOverlay::class)->sync();

        $this->assertSame('pink', config('tpanels.theme'), 'the tenant tier stays above the operator after a mid-hop sync');
        $this->assertSame('light', app(ConfigOverlay::class)->base('tpanels')['theme'], "the operator's base is the host value, never the tenant's");

        app(ConfigTenantOverlay::class)->revert();
        $context->leave();
        $this->assertSame('dark', config('tpanels.theme'), "revert lands on the operator's new value");
    }

    public function test_the_overlay_never_holds_a_repository_and_a_fresh_clone_starts_central(): void
    {
        $this->as('tenant-admin');
        $this->set('/review', true, 'a')->assertOk();
        $this->publish('a')->assertOk();

        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        $worker = app('config');

        // Octane's sandbox: the request runs on a CLONE bound as `config`; the hop must write the clone only.
        $clone = clone $worker;
        $this->app->instance('config', $clone);
        app(ConfigTenantOverlay::class)->apply();
        $this->assertTrue($clone->get('tpanels.review'));
        $this->assertFalse($worker->get('tpanels.review'), "the worker's base config is never written");

        app(ConfigTenantOverlay::class)->revert();
        $this->assertFalse($clone->get('tpanels.review'));
        $this->app->instance('config', $worker);
        $context->leave();
    }

    public function test_the_access_read_serves_the_tenant_row_inside_a_tenant(): void
    {
        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();

        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        app(ConfigTenantOverlay::class)->apply();

        try {
            $rows = app(ConfigSectionAccess::class)->rows(auth()->user(), 'tpanels');
            $this->assertNotNull($rows);
            $this->assertSame(ConfigTier::Tenant, $rows[0]->tier);
            $this->assertSame('pink', $rows[0]->values['theme']);
        } finally {
            app(ConfigTenantOverlay::class)->revert();
            $context->leave();
        }
    }

    /* ---- review-r1 (07 Spec, CHANGES REQUESTED): a lock holds at ANY depth, and every leaf beneath a published parent
     * reads what the runtime holds (owned: the entry's; inherited or cut: the tier below; neither: absent). ---- */

    /**
     * Inside tenant `$tenant`'s hop, every leaf of `config('tpanels')` is a row with the same value, and a row reported
     * ABSENT is absent at runtime.
     *
     * @param  array<string, mixed>  $row  a row read in that tenant's realm
     */
    private function assertTenantRowAgreesWithRuntime(array $row, string $tenant, string $root = 'tpanels', ?array $runtime = null): void
    {
        $runtime ??= $this->probe($tenant, $root);
        $section = app(ConfigSectionRegistry::class)->get($root)->section;
        $keys = collect($row['keys'])->keyBy('pointer');

        foreach (\Splicewire\Beam\Config\JsonPointer::leaves($runtime) as $pointer) {
            if (! $keys->has($pointer)) {
                // Only keys above the tenant ceiling are left out of the tenant realm.
                $this->assertFalse($section::keyDeclaration(\Splicewire\Beam\Config\JsonPointer::segments($pointer))->ceiling->admits(ConfigTier::Tenant), "{$root}{$pointer} has a row");

                continue;
            }

            [, $value] = \Splicewire\Beam\Config\JsonPointer::get($runtime, $pointer);
            $this->assertSame($value, $keys[$pointer]['value'], "{$pointer}: the row reads what config() holds");
        }

        foreach ($keys as $pointer => $key) {
            if (collect($key['layers'])->last()['removed'] ?? false) {
                $this->assertFalse(\Splicewire\Beam\Config\JsonPointer::get($runtime, $pointer)[0], "{$pointer}: reported absent, and absent at runtime");
            }
        }
    }

    /** @param  array<string, mixed>  $value */
    private function setGroup(array $value, string $tenant = 'a'): TestResponse
    {
        return $this->set('/group', $value, $tenant);
    }

    private function lock(string $pointer, string $reason = 'Set by the operator.', string $root = 'tpanels'): void
    {
        $this->as('operator');
        $this->op('lock', ['root' => $root, 'pointer' => $pointer, 'reason' => $reason])->assertOk();
        $this->as('tenant-admin');
    }

    public function test_a_lock_on_a_child_holds_against_a_published_tenant_parent(): void
    {
        $this->as('tenant-admin');
        $this->setGroup(['review' => true, 'label' => 'Tenant label', 'deploy' => 'host-driver', 'settings' => self::SETTINGS])->assertOk();
        $this->publish('a')->assertOk();
        $this->assertTrue($this->probe('a')['group']['review']);

        $this->lock('/group/review', 'Review is the operator\'s.');

        $group = $this->probe('a')['group'];
        $this->assertFalse($group['review'], 'the locked child takes the central value at the next hop');
        $this->assertSame('Tenant label', $group['label'], 'the unlocked sibling stays the tenant\'s');

        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $review = $this->key($row, '/group/review');
        $this->assertNotSame('tenant', $review['tier']);
        $this->assertFalse($review['value']);
        $this->assertSame('Review is the operator\'s.', $review['lock']['reason']);
        $this->assertSame('tenant', $this->key($row, '/group/label')['tier']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    public function test_a_lock_on_the_parent_covers_every_child_of_a_published_tenant_parent(): void
    {
        $this->as('tenant-admin');
        $this->setGroup(['review' => true, 'label' => 'Tenant label', 'deploy' => 'host-driver', 'settings' => self::SETTINGS])->assertOk();
        $this->publish('a')->assertOk();

        $this->lock('/group');

        $this->assertSame(['review' => false, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => self::SETTINGS], $this->probe('a')['group']);
        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertNotSame('tenant', $this->key($row, '/group/review')['tier']);
        $this->assertNotSame('tenant', $this->key($row, '/group/label')['tier']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    public function test_a_lock_below_what_a_tenant_map_holds_still_holds_against_its_replace(): void
    {
        $this->as('tenant-admin');
        $this->set('/routes', ['a' => 'A2'], 'a')->assertOk();
        $this->publish('a')->assertOk();
        $this->assertSame(['a' => 'A2'], $this->probe('a')['routes'], 'the tenant map replaces: b is dropped');

        $this->lock('/routes/b');

        $this->assertSame(['a' => 'A2', 'b' => 'B'], $this->probe('a')['routes'], 'the locked b is the central value, never dropped by the replace');
        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertSame('package', $this->key($row, '/routes/b')['tier']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    public function test_a_tenant_map_replace_reports_the_entry_it_leaves_out_as_absent_under_the_tenant(): void
    {
        $this->as('tenant-admin');
        $this->set('/routes', ['a' => 'A2'], 'a')->assertOk();
        $row = $this->publish('a')->assertOk()->json('data');

        $this->assertSame('tenant', $this->key($row, '/routes/a')['tier']);
        $this->assertSame('A2', $this->key($row, '/routes/a')['value']);
        $this->assertSame('tenant', $this->key($row, '/routes/b')['tier'], "b's absence is the tenant's replace");
        $this->assertNull($this->key($row, '/routes/b')['value']);
        $this->assertTrue(collect($this->key($row, '/routes/b')['layers'])->last()['removed']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    public function test_the_tenant_tier_resolves_over_the_operator_at_mixed_depths(): void
    {
        $this->as('operator');
        $this->set('/group/label', 'Operator label')->assertOk();
        $this->publish()->assertOk();

        $this->as('tenant-admin');
        $this->set('/group/review', true, 'a')->assertOk();
        $row = $this->publish('a')->assertOk()->json('data');

        $this->assertSame(['tenant', true], [$this->key($row, '/group/review')['tier'], $this->key($row, '/group/review')['value']]);
        $this->assertSame(['operator', 'Operator label'], [$this->key($row, '/group/label')['tier'], $this->key($row, '/group/label')['value']]);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
        $this->assertSame('Operator label', $this->probe('b')['group']['label']);
    }

    /** build-qa (07 Standards): the WRITE side of a lock inside a parent object. */
    public function test_a_tenant_parent_write_that_changes_a_locked_child_is_refused_with_the_reason(): void
    {
        $this->lock('/group/review', 'Review is the operator\'s.');

        $refused = $this->setGroup(['review' => true, 'label' => 'Tenant label', 'deploy' => 'host-driver', 'settings' => self::SETTINGS])->assertStatus(422);
        $this->assertStringContainsString('/group/review is locked by the operator: Review is the operator\'s.', (string) $refused->json('message'));
    }

    public function test_a_value_equal_locked_child_resent_inside_a_parent_is_inherited_never_tenant_owned(): void
    {
        $this->lock('/group/review');

        $this->setGroup(['review' => false, 'label' => 'Tenant label', 'deploy' => 'host-driver', 'settings' => self::SETTINGS])->assertOk();
        $this->publish('a')->assertOk();

        $entry = json_decode((string) DB::connection('tenant_a')->table(Beam::table('config_scopes'))->where('section', 'tpanels')->value('draft'), true)['/group'];
        $this->assertContains('/review', $entry['inherit'], 'the locked child is inherited');
        $this->assertArrayNotHasKey('review', $entry['value'], 'it is never stored as tenant-owned');

        // The operator's central value moves; the tenant's hop follows it.
        $this->as('operator');
        $this->set('/group/review', true)->assertOk();
        $this->publish()->assertOk();
        $this->as('tenant-admin');
        $this->assertTrue($this->probe('a')['group']['review']);
        $this->assertSame('Tenant label', $this->probe('a')['group']['label']);
    }

    /* ---- review-r1 01:26Z (residual HIGH): a lock below a NON-OBJECT parent. The walk: a null or scalar on the way is
     * cut whole at that node (the entry is dropped when the node is the entry itself); an array (empty included) is
     * descended; a missing key inherits only the locked key. ---- */

    // Regression credited to review-r1@config-sections (07 Spec, residual HIGH): the case reported was a published null parent over a locked child.
    public function test_a_child_lock_drops_a_published_explicit_null_parent(): void
    {
        $this->as('tenant-admin');
        $this->op('save-draft', ['root' => 'tpanels', 'pointer' => '/extras', 'op' => 'null'], 'a')->assertOk();
        $this->publish('a')->assertOk();
        $this->assertNull($this->probe('a')['extras'], 'the tenant\'s explicit null applies');

        $this->lock('/extras/k', 'k is the operator\'s.');

        $this->assertSame(['k' => 'K', 'm' => 'M'], $this->probe('a')['extras'], 'the lock wins: the null parent cannot be kept in part, so it is dropped');
        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertSame(['package', 'K'], [$this->key($row, '/extras/k')['tier'], $this->key($row, '/extras/k')['value']]);
        $this->assertSame('k is the operator\'s.', $this->key($row, '/extras/k')['lock']['reason']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    /**
     * An empty-map parent is kept as a map plus the locked key. (Exercised on restrict() and the fold directly: the ops
     * cannot publish an empty map today, since `{}` decodes to PHP's `[]`, which the section schema reads as a list. That
     * is 05's store behaviour, reported separately, not 07's.)
     */
    public function test_a_child_lock_keeps_an_empty_map_parent_as_a_map_plus_the_locked_key(): void
    {
        $registered = app(ConfigSectionRegistry::class)->get('tpanels');
        $locks = ['/extras/k' => new \Splicewire\Beam\Config\Overrides\ConfigOverrideLock(['section' => 'tpanels', 'pointer' => '/extras/k', 'reason' => 'x'])];
        $central = config('tpanels');
        $entry = new \Splicewire\Beam\Config\Overrides\ConfigOverrideEntry('/extras', \Splicewire\Beam\Enums\ConfigOverrideOp::Set, [], false, 'r1', 1, null);

        $kept = ConfigTenantOverlay::restrict($registered, ['/extras' => $entry], $locks, $central)['/extras'];

        $this->assertSame([], $kept->value, 'every unlocked entry stays removed');
        $this->assertSame(['/k'], $kept->inherit);
        $this->assertSame(['k' => 'K'], $kept->valueOver($central), 'the locked one is central');

        $keys = \Splicewire\Beam\Config\ConfigLadder::fold(package: $central, hostKeys: null, observed: $central, tenant: [
            '/extras' => new \Splicewire\Beam\Config\ConfigLayerValue(ConfigTier::Tenant, $kept->valueOver($central), 'tenant override · v1', owned: $kept->value, inherit: $kept->inherit),
        ])->keys;
        $this->assertSame([ConfigTier::Package, 'K'], [$keys['/extras/k']->tier, $keys['/extras/k']->value]);
        $this->assertSame(ConfigTier::Tenant, $keys['/extras/m']->tier, "m's absence is still the tenant's replace");
        $this->assertTrue(end($keys['/extras/m']->layers)->removed);
    }

    public function test_the_walk_drops_a_scalar_parent_and_cuts_a_nested_non_object_whole(): void
    {
        $registered = app(ConfigSectionRegistry::class)->get('tpanels');
        $locks = ['/extras/k' => new \Splicewire\Beam\Config\Overrides\ConfigOverrideLock(['section' => 'tpanels', 'pointer' => '/extras/k', 'reason' => 'x'])];
        $entry = fn (string $pointer, mixed $value) => new \Splicewire\Beam\Config\Overrides\ConfigOverrideEntry($pointer, \Splicewire\Beam\Enums\ConfigOverrideOp::Set, $value, false, 'r1', 1, null);
        $central = config('tpanels');

        // A scalar parent cannot be kept in part: dropped.
        $this->assertSame([], ConfigTenantOverlay::restrict($registered, ['/extras' => $entry('/extras', 'flat')], $locks, $central));

        // A nested null on the way to a deeper lock is cut whole at that node, and inherits whole from central.
        $deep = ['/routes/c/x' => new \Splicewire\Beam\Config\Overrides\ConfigOverrideLock(['section' => 'tpanels', 'pointer' => '/routes/c/x', 'reason' => 'x'])];
        $kept = ConfigTenantOverlay::restrict($registered, ['/routes' => $entry('/routes', ['a' => 'A2', 'c' => null])], $deep, $central);
        $this->assertSame(['a' => 'A2'], $kept['/routes']->value);
        $this->assertSame(['/c'], $kept['/routes']->inherit);

        // A missing key on the way inherits only the locked key.
        $kept = ConfigTenantOverlay::restrict($registered, ['/routes' => $entry('/routes', ['a' => 'A2'])], $deep, $central);
        $this->assertSame(['/c/x'], $kept['/routes']->inherit);
    }

    public function test_a_child_whose_ceiling_no_longer_admits_the_tenant_is_cut_and_inherited(): void
    {
        // A published tenant entry holding a host-only child: what a package narrowing a ceiling after the publish leaves.
        $registered = app(ConfigSectionRegistry::class)->get('tpanels');
        $entry = new \Splicewire\Beam\Config\Overrides\ConfigOverrideEntry('/group', \Splicewire\Beam\Enums\ConfigOverrideOp::Set, ['review' => true, 'deploy' => 'tenant-driver'], false, 'r1', 1, null);

        $kept = ConfigTenantOverlay::restrict($registered, ['/group' => $entry], []);

        $this->assertSame(['review' => true], $kept['/group']->value);
        $this->assertSame(['/deploy'], $kept['/group']->inherit);
        $this->assertSame(['review' => true, 'deploy' => 'host-driver'], $kept['/group']->valueOver(config('tpanels')), 'the cut child takes the central value');
    }

    /* ---- review-r1 02:59Z (07 Spec, HIGH on bc5061d): the NEAREST-DECLARATION ownership boundary. `/group` admits the
     * tenant, `/group/settings` is operator-owned, `/group/settings/label` (and the map `tags`) explicitly admit the tenant
     * again. The write side, the runtime cut and the tenant row all draw it through one walk, ConfigTierBoundary. ---- */

    /** @return array{value: mixed, inherit: list<string>} the tenant's stored entry at `$pointer` */
    private function storedEntry(string $pointer, string $root = 'tpanels', string $tenant = 'a'): array
    {
        return json_decode((string) DB::connection("tenant_{$tenant}")->table(Beam::table('config_scopes'))->where('section', $root)->value('draft'), true)[$pointer];
    }

    // Regression credited to review-r1@config-sections (07 Spec, HIGH on bc5061d): the case reported was a tenant-owned child of an operator-owned record, silently restored to central by the runtime cut.
    public function test_a_tenant_owned_child_of_an_operator_owned_record_survives_a_parent_write(): void
    {
        $this->as('tenant-admin');
        $this->setGroup(['review' => false, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => ['label' => 'tenant'] + self::SETTINGS])->assertOk();
        $this->publish('a')->assertOk();

        $entry = $this->storedEntry('/group');
        $this->assertSame(['label' => 'tenant', 'tags' => ['x' => 'X']], $entry['value']['settings'], 'the write keeps the tenant-owned children');
        $this->assertContains('/settings/fixed', $entry['inherit']);
        $this->assertNotContains('/settings', $entry['inherit'], 'only the refused leaf is inherited, never the record whole');

        $this->assertEquals(['label' => 'tenant', 'fixed' => 'operator fixed', 'tags' => ['x' => 'X']], $this->probe('a')['group']['settings'], 'the runtime keeps the tenant child');
        $this->assertSame('central', $this->probe('b')['group']['settings']['label']);

        // The operator moves the inherited sibling; the tenant's hop follows it and keeps its own child.
        $this->as('operator');
        $this->set('/group/settings/fixed', 'operator moved')->assertOk();
        $this->publish()->assertOk();
        $this->as('tenant-admin');
        $this->assertSame(['tenant', 'operator moved'], [$this->probe('a')['group']['settings']['label'], $this->probe('a')['group']['settings']['fixed']]);

        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertSame(['tenant', 'tenant'], [$this->key($row, '/group/settings/label')['tier'], $this->key($row, '/group/settings/label')['value']]);
        $this->assertNull($this->key($row, '/group/settings/fixed'), 'the operator-only sibling is not offered to the tenant');
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    public function test_the_direct_child_control_a_tenant_writes_the_child_and_not_its_operator_owned_record_or_sibling(): void
    {
        $this->as('tenant-admin');
        $this->set('/group/settings/label', 'direct', 'a')->assertOk();
        $row = $this->publish('a')->assertOk()->json('data');

        $this->assertSame(['label' => 'direct', 'fixed' => 'operator fixed', 'tags' => ['x' => 'X']], $this->probe('a')['group']['settings']);
        $this->assertSame('tenant', $this->key($row, '/group/settings/label')['tier']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');

        $this->assertStringContainsString('ceiling operator', (string) $this->set('/group/settings/fixed', 'x', 'a')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('ceiling operator', (string) $this->set('/group/settings', ['label' => 'x'] + self::SETTINGS, 'a')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('ceiling operator', (string) $this->setGroup(['review' => false, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => ['fixed' => 'changed'] + self::SETTINGS])->assertStatus(422)->json('message'));
    }

    public function test_a_lock_on_the_operator_owned_record_holds_its_tenant_owned_child_too(): void
    {
        $this->as('tenant-admin');
        $this->set('/group/settings/label', 'direct', 'a')->assertOk();
        $this->setGroup(['review' => true, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => ['label' => 'parent'] + self::SETTINGS])->assertOk();
        $this->publish('a')->assertOk();
        $this->assertSame('direct', $this->probe('a')['group']['settings']['label'], 'the deeper entry wins over the parent');

        // A lock holds its whole subtree: a child's own declaration never narrows it.
        $this->lock('/group/settings', 'Settings are the operator\'s.');

        $this->assertSame(self::SETTINGS, $this->probe('a')['group']['settings']);
        $this->assertTrue($this->probe('a')['group']['review'], 'the parent entry keeps what the lock does not cover');
        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertNotSame('tenant', $this->key($row, '/group/settings/label')['tier']);
        $this->assertSame('Settings are the operator\'s.', $this->key($row, '/group/settings/label')['lock']['reason']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');

        $this->assertStringContainsString('locked by the operator: Settings are the operator\'s.', (string) $this->set('/group/settings/label', 'again', 'a')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('locked by the operator', (string) $this->setGroup(['review' => true, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => ['label' => 'again'] + self::SETTINGS])->assertStatus(422)->json('message'));
    }

    public function test_a_tenant_map_under_an_operator_owned_record_replaces_and_a_later_entry_lock_holds(): void
    {
        $this->as('tenant-admin');
        $this->setGroup(['review' => false, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => ['tags' => ['y' => 'Y']] + self::SETTINGS])->assertOk();
        $row = $this->publish('a')->assertOk()->json('data');

        $this->assertSame(['y' => 'Y'], $this->probe('a')['group']['settings']['tags'], 'the tenant map replaces: x is dropped');
        $this->assertSame('tenant', $this->key($row, '/group/settings/tags/y')['tier']);
        $this->assertTrue(collect($this->key($row, '/group/settings/tags/x')['layers'])->last()['removed']);
        $this->assertTenantRowAgreesWithRuntime($row, 'a');

        $this->lock('/group/settings/tags/x');

        $this->assertSame(['y' => 'Y', 'x' => 'X'], $this->probe('a')['group']['settings']['tags'], 'the locked entry is central; the replace keeps y');
        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertTenantRowAgreesWithRuntime($row, 'a');
    }

    public function test_a_null_operator_owned_record_in_a_tenant_parent_inherits_the_record_the_operator_publishes_later(): void
    {
        $this->as('operator');
        $this->op('save-draft', ['root' => 'tpanels', 'pointer' => '/group/settings', 'op' => 'null'])->assertOk();
        $this->publish()->assertOk();

        // Central holds null: a tenant parent write resending it is a value-equal resend of an operator-owned key.
        $this->as('tenant-admin');
        $this->setGroup(['review' => true, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => null])->assertOk();
        $this->publish('a')->assertOk();
        $this->assertContains('/settings', $this->storedEntry('/group')['inherit']);
        $this->assertNull($this->probe('a')['group']['settings']);

        $this->as('operator');
        $this->set('/group/settings', ['label' => 'op', 'fixed' => 'op fixed', 'tags' => ['z' => 'Z']])->assertOk();
        $this->publish()->assertOk();

        $this->as('tenant-admin');
        $this->assertSame(['label' => 'op', 'fixed' => 'op fixed', 'tags' => ['z' => 'Z']], $this->probe('a')['group']['settings'], "the tenant's null never hides the operator's record");
        $this->assertTrue($this->probe('a')['group']['review']);
        $row = $this->set('/theme', 'pink', 'a')->assertOk()->json('data');
        $this->assertTenantRowAgreesWithRuntime($row, 'a');

        // With an object in central again, a tenant parent write nulling the record would change the operator's leaf.
        $this->setGroup(['review' => true, 'label' => 'base', 'deploy' => 'host-driver', 'settings' => null])->assertStatus(422);
    }

    /* ---- root 29b67e82: a tenant row is REDACTED at the source. A workspace admin sees each key's value, its tier as a
     * tier, and a lock with its reason; never the install's env variable names, config file paths or the package and
     * host layers' sources. The operator row keeps all of them (the negative control). ---- */

    private const INSTALL_DETAIL = [
        'BEAM_FIXTURE_TPANELS_THEME', // an env() binding in the package file (a tenant-offered key)
        'BEAM_FIXTURE_TPANELS_REVIEW', // a DECLARED env (the key's `env` and the schema's x-beam-config.env)
        'BEAM_FIXTURE_TPANELS_DRIVER', // an env() binding in the host file
        'package/tpanels.php', // the package file: the section's `files.package` and the package layer's source
        'host/tpanels.php', // the host file: `files.host`, and the host layer's source
    ];

    public function test_a_tenant_row_names_no_env_variable_file_path_or_layer_source_on_any_read(): void
    {
        // COMPOUND keys (review 05:17Z): the operator AND the tenant each publish the whole /group record, so the fold
        // emits /group as one key whose value is the record, host-only `deploy` and operator-only `settings/fixed`
        // included. The operator's own values are distinctive strings, so a leak shows in the serialized JSON.
        $this->as('operator');
        $this->set('/group', ['review' => false, 'label' => 'Operator label', 'deploy' => 'host-driver', 'settings' => ['fixed' => 'OPERATOR-ONLY-FIXED'] + self::SETTINGS])->assertOk();
        $this->publish()->assertOk();
        $this->lock('/group/review', 'Review is the operator\'s.');
        $this->as('tenant-admin');
        $this->set('/review', true, 'a')->assertOk();
        $this->setGroup(['review' => false, 'label' => 'Tenant label', 'deploy' => 'host-driver', 'settings' => ['label' => 'tenant', 'fixed' => 'OPERATOR-ONLY-FIXED'] + self::SETTINGS])->assertOk();

        $published = $this->publish('a')->assertOk()->json('data');
        $index = collect($this->getJson('/beam-config', ['X-Tenant' => 'a'])->assertOk()->json('data'))->firstWhere('root', 'tpanels');
        $this->assertNotNull($index, 'the resource\'s own list read serves the tenant row');
        [, $access] = $this->tenantView('tpanels', 'a');

        foreach (['publish op' => $published, 'resource list' => $index, 'access (MCP) read' => $access] as $door => $row) {
            $json = (string) json_encode($row, JSON_UNESCAPED_SLASHES);

            foreach ([...self::INSTALL_DETAIL, 'host-driver', 'OPERATOR-ONLY-FIXED'] as $detail) {
                $this->assertStringNotContainsString($detail, $json, "{$door}: the tenant row names {$detail} ANYWHERE");
            }

            $this->assertSame(['package' => null, 'host' => null], $row['files'], "{$door}: no config file paths");
            $this->assertNotNull($this->key($row, '/group'), "{$door}: the compound /group key is offered");
            $this->assertArrayNotHasKey('deploy', $this->key($row, '/group')['value'], "{$door}: the compound key's value is pruned");

            foreach ($row['keys'] as $key) {
                $this->assertNull($key['env'], "{$door}: {$key['pointer']} names no env variable");
                $this->assertFalse($key['hostShadowsPackage'], $door);

                // ONE inherited entry, then at most the tenant's own layer (lead 05:17Z).
                $this->assertSame('inherited', $key['layers'][0]['source'], "{$door}: {$key['pointer']}'s first layer is the inherited value");
                $this->assertLessThanOrEqual(2, count($key['layers']), "{$door}: {$key['pointer']} lists no lower layers one by one");

                if (isset($key['layers'][1])) {
                    $this->assertSame('tenant', $key['layers'][1]['tier'], $door);
                }
            }

            // The inherited entry is the central effective value with its tier as a tier: theme falls back to the package
            // default, group/label to the operator's.
            $this->assertSame(['package', 'light'], [$this->key($row, '/theme')['layers'][0]['tier'], $this->key($row, '/theme')['layers'][0]['value']], $door);
            $this->assertSame(['operator', 'Operator label'], [$this->key($row, '/group/label')['layers'][0]['tier'], $this->key($row, '/group/label')['layers'][0]['value']], $door);

            // Keys the tenant is NOT offered leave no value and no schema property: the host-ceilinged `driver` and the
            // operator-only `limit` (lead 05:12Z), nor the host-only `group/deploy` and operator-only
            // `group/settings/fixed` below offered records.
            $this->assertArrayNotHasKey('driver', $row['values'], "{$door}: the host-ceilinged driver's value");
            $this->assertArrayNotHasKey('limit', $row['values'], "{$door}: the operator-only limit's value");
            $this->assertArrayNotHasKey('deploy', $row['values']['group'], "{$door}: the host-only group/deploy's value");
            $this->assertArrayNotHasKey('fixed', $row['values']['group']['settings'], "{$door}: the operator-only group/settings/fixed's value");
            $this->assertArrayNotHasKey('driver', $row['schema']['properties'], "{$door}: the schema names driver");
            $this->assertArrayNotHasKey('limit', $row['schema']['properties'], "{$door}: the schema names limit");
            $this->assertNotContains('driver', $row['schema']['required'], $door);
            $this->assertSame(['review' => true, 'theme' => 'light'], array_intersect_key($row['values'], ['review' => 1, 'theme' => 1]), "{$door}: an offered key keeps its value");
            $this->assertSame('tenant', $row['values']['group']['settings']['label'], "{$door}: an offered child of a refused record keeps its value");
            $this->assertSame('tenant', $this->key($row, '/group')['value']['settings']['label'], "{$door}: and so does the compound key's");

            // What a tenant DOES see: the value, its tier as a tier, and a lock with its reason.
            $this->assertSame([true, 'tenant'], [$this->key($row, '/review')['value'], $this->key($row, '/review')['tier']], $door);
            $this->assertSame(['light', 'package'], [$this->key($row, '/theme')['value'], $this->key($row, '/theme')['tier']], $door);
            $this->assertSame('Review is the operator\'s.', $this->key($row, '/group/review')['lock']['reason'], $door);
            $this->assertStringStartsWith('tenant override · v', (string) collect($this->key($row, '/review')['layers'])->last()['source'], "{$door}: the tenant's own layer keeps its version");
        }

        // The negative control: the operator row still names every one of them.
        $this->as('operator');
        $operator = (string) json_encode($this->set('/theme', 'dark')->assertOk()->json('data'), JSON_UNESCAPED_SLASHES);
        $operatorIndex = (string) json_encode(collect($this->getJson('/beam-config')->assertOk()->json('data'))->firstWhere('root', 'tpanels'), JSON_UNESCAPED_SLASHES);

        foreach (self::INSTALL_DETAIL as $detail) {
            $this->assertStringContainsString($detail, $operator, "the operator row still names {$detail}");
            $this->assertStringContainsString($detail, $operatorIndex, "the operator's list read still names {$detail}");
        }

        foreach ([$operator, $operatorIndex] as $json) {
            $this->assertStringContainsString('OPERATOR-ONLY-FIXED', $json, 'the operator row still has the operator-only value');
            $row = json_decode($json, true);
            $this->assertSame(['array', 10], [$row['values']['driver'], $row['values']['limit']], 'the operator row still has every value');
            $this->assertSame('host-driver', $row['values']['group']['deploy']);
            $this->assertArrayHasKey('driver', $row['schema']['properties']);
        }
    }

    public function test_a_map_shaped_sections_tenant_row_prunes_its_entry_schema_and_values_too(): void
    {
        // `fleet.gauges` is map-shaped (entries: GaugeEntry), and none of an entry's keys admits the tenant: its schema's
        // entry properties live under `additionalProperties`, a host file binds an env var, and its values are the host's.
        app(ConfigSectionRegistry::class)->section(\Splicewire\Beam\Tests\Fixtures\Config\GaugesSection::class, owner: 'host');
        config()->set('fleet.gauges', require self::FIXTURES.'/host/fleet/gauges.php');
        $this->as('tenant-admin');

        [, $tenant] = $this->tenantView('fleet.gauges', 'a');
        $json = (string) json_encode($tenant, JSON_UNESCAPED_SLASHES);

        $this->assertSame([], $tenant['keys']);
        $this->assertSame([], $tenant['values']);
        $this->assertSame([], $tenant['schema']['additionalProperties']['properties'], 'the entry schema names no key the tenant is not offered');
        $this->assertSame([], $tenant['schema']['additionalProperties']['required'] ?? []);

        foreach (['BEAM_FIXTURE_CSE_COST_USD', 'Google web search', 'fleet/gauges.php', 'unit_cost_usd'] as $detail) {
            $this->assertStringNotContainsString($detail, $json, "the tenant's map row names {$detail}");
        }

        // The negative control: the operator's row of the same section keeps all of it.
        $this->as('operator');
        $operator = (string) json_encode(app(ConfigSectionAccess::class)->rows(auth()->user(), 'fleet.gauges')[0]->toArray(), JSON_UNESCAPED_SLASHES);

        foreach (['BEAM_FIXTURE_CSE_COST_USD', 'Google web search', 'fleet/gauges.php', 'unit_cost_usd'] as $detail) {
            $this->assertStringContainsString($detail, $operator, "the operator's map row still names {$detail}");
        }
    }

    /**
     * The boundary over GENERATED trees (lead 03:01Z): random nesting of records, maps and leaves declared Tenant,
     * Operator, Host or undeclared, nullable records and maps (null central values included), and random operator locks.
     * For each seed a tenant writes a random entry it may write, changing what it may change and resending the rest
     * (dropping or adding map entries where the map is its), through the real save and publish ops, and then:
     * - normalize's inherit set and stored value equal what restrict() cuts from the raw written value;
     * - config() inside the tenant's hop holds, leaf by leaf, the tenant's value where the tenant owns the leaf by its
     *   nearest declaration and no lock covers it, and the central value everywhere else; tenant b sees central;
     * - the tenant row agrees with config();
     * - after a LATER random lock below the entry, the same three hold with the new lock.
     * CS07_GEN_SEED / CS07_GEN_SEEDS widen it; a failure names its seed.
     */
    public function test_generated_trees_hold_one_ownership_boundary_across_write_runtime_and_row(): void
    {
        $first = (int) (getenv('CS07_GEN_SEED') ?: 7000);
        $ran = 0;

        for ($seed = $first, $last = $first + (int) (getenv('CS07_GEN_SEEDS') ?: 40); $seed < $last; $seed++) {
            $ran += $this->generatedCase($seed) ? 1 : 0;
        }

        $this->assertGreaterThan(0, $ran);
        fwrite(STDERR, "\n[cs07-gen] seeds {$first}..".($last - 1).": {$ran} ran a tenant write\n");
    }

    private function generatedCase(int $seed): bool
    {
        $tree = \Splicewire\Beam\Tests\Fixtures\Config\GeneratedConfigTree::make($seed);
        $root = $tree->root;
        $file = sys_get_temp_dir()."/beam-cs07-gen-{$seed}-".getmypid().'.php';
        file_put_contents($file, '<?php return '.var_export($tree->central, true).';');
        config()->set($root, $tree->central);
        app(ConfigSectionRegistry::class)->section($tree->section, owner: 'acme/generated', packageFile: $file);
        app(ConfigOverlay::class)->sync();
        $registered = app(ConfigSectionRegistry::class)->get($root);
        $nodes = $this->generatedNodes($tree->central);
        $at = "seed {$seed}";

        try {
            // Operator locks, on keys a tenant could otherwise write (as the lock op requires).
            $lockable = array_values(array_filter($nodes, fn (array $s): bool => $this->generatedLockable($registered, $tree->central, $s)));

            for ($n = $tree->int(0, 2); $n > 0 && $lockable !== []; $n--) {
                $this->lock(\Splicewire\Beam\Config\JsonPointer::from($lockable[$tree->int(0, count($lockable) - 1)]), 'Generated.', $root);
            }

            $locks = app(ConfigOverrideStore::class)->locks($root);
            $writable = array_values(array_filter($nodes, fn (array $s): bool => \Splicewire\Beam\Config\Overrides\ConfigTierBoundary::admits($registered, $s, ConfigTier::Tenant, $locks)));

            if ($writable === []) {
                return false;
            }

            $segments = $writable[$tree->int(0, count($writable) - 1)];
            $pointer = \Splicewire\Beam\Config\JsonPointer::from($segments);
            $raw = $this->generatedMutate($tree, $registered, $segments, \Splicewire\Beam\Config\JsonPointer::get($tree->central, $pointer)[1], $locks);
            $at .= ", entry {$pointer} = ".json_encode($raw).', locks '.json_encode(array_keys($locks)).', central '.json_encode($tree->central);

            $this->as('tenant-admin');
            $saved = $this->op('save-draft', ['root' => $root, 'pointer' => $pointer, 'op' => 'set', 'value' => json_encode($raw)], 'a');
            $this->assertSame(200, $saved->status(), "{$at}: the write is refused: ".$saved->json('message'));
            $row = $this->op('publish', ['root' => $root], 'a')->assertOk()->json('data');

            // 1. The write side and the runtime cut draw one boundary.
            $stored = $this->storedEntry($pointer, $root);
            $kept = ConfigTenantOverlay::restrict($registered, [$pointer => new \Splicewire\Beam\Config\Overrides\ConfigOverrideEntry($pointer, \Splicewire\Beam\Enums\ConfigOverrideOp::Set, $raw, false, 'r', 1, null)], $locks, $tree->central);
            $this->assertArrayHasKey($pointer, $kept, "{$at}: restrict drops an entry the write accepted");
            $this->assertEqualsCanonicalizing($stored['inherit'] ?? [], $kept[$pointer]->inherit, "{$at}: normalize's inherit set == restrict's cut set");
            $this->assertEquals($stored['value'], $kept[$pointer]->value, "{$at}: normalize's stored value == restrict's kept value");

            // 2. The runtime, by the nearest-declaration rule; 3. the row.
            $this->assertGeneratedRuntime($registered, $tree->central, $segments, $raw, $locks, $this->probe('a', $root), $at);
            $this->assertEquals($tree->central, $this->probe('b', $root), "{$at}: tenant b sees central");
            $this->assertTenantRowAgreesWithRuntime($row, 'a', $root);

            // 4. A later lock below (or at) the entry.
            $below = array_values(array_filter($lockable, fn (array $s): bool => array_slice($s, 0, count($segments)) === $segments && ! isset($locks[\Splicewire\Beam\Config\JsonPointer::from($s)])));

            if ($below !== []) {
                $later = \Splicewire\Beam\Config\JsonPointer::from($below[$tree->int(0, count($below) - 1)]);
                $this->lock($later, 'Generated later.', $root);
                $locks = app(ConfigOverrideStore::class)->locks($root);
                [$runtime, $row] = $this->tenantView($root, 'a');
                $this->assertGeneratedRuntime($registered, $tree->central, $segments, $raw, $locks, $runtime, "{$at}, later lock {$later}");
                $this->assertTenantRowAgreesWithRuntime($row, 'a', $root, $runtime);
            }

            return true;
        } finally {
            @unlink($file);
        }
    }

    /**
     * Every node path of `$value` (records, maps, map entries, leaves; nulls included).
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $path
     * @return list<list<string>>
     */
    private function generatedNodes(array $value, array $path = []): array
    {
        $nodes = [];

        foreach ($value as $key => $child) {
            $nodes[] = [...$path, (string) $key];

            if (is_array($child)) {
                array_push($nodes, ...$this->generatedNodes($child, [...$path, (string) $key]));
            }
        }

        return $nodes;
    }

    /** @param  list<string>  $segments */
    private function generatedLockable(\Splicewire\Beam\Config\RegisteredConfigSection $registered, array $central, array $segments): bool
    {
        [, $node] = \Splicewire\Beam\Config\JsonPointer::get($central, \Splicewire\Beam\Config\JsonPointer::from($segments));

        return \Splicewire\Beam\Config\Overrides\ConfigTierBoundary::admits($registered, $segments, ConfigTier::Tenant)
            || (is_array($node) && \Splicewire\Beam\Config\Overrides\ConfigTierBoundary::admitsBelow($registered, $segments, $node, ConfigTier::Tenant));
    }

    /**
     * What a tenant writes at `$segments`: each leaf it owns changed at random, each map it owns with entries dropped or
     * added at random (never emptied: an empty map cannot be published, the 05/13 finding), everything else resent as is.
     *
     * @param  list<string>  $segments
     * @param  array<string, \Splicewire\Beam\Config\Overrides\ConfigOverrideLock>  $locks
     */
    private function generatedMutate(\Splicewire\Beam\Tests\Fixtures\Config\GeneratedConfigTree $tree, \Splicewire\Beam\Config\RegisteredConfigSection $registered, array $segments, mixed $node, array $locks): mixed
    {
        $owns = fn (array $s): bool => \Splicewire\Beam\Config\Overrides\ConfigTierBoundary::admits($registered, $s, ConfigTier::Tenant, $locks);

        if (! is_array($node)) {
            return is_string($node) && $owns($segments) && $tree->chance(60) ? 't'.$tree->int(100, 999) : $node;
        }

        if ($tree->kind($segments) === 'map' && $owns($segments)) {
            foreach (array_keys($node) as $key) {
                $entry = [...$segments, (string) $key];

                if (! $owns($entry)) {
                    continue;
                }

                if (count($node) > 1 && $tree->chance(30)) {
                    unset($node[$key]);
                } elseif ($tree->chance(50)) {
                    $node[$key] = 't'.$tree->int(100, 999);
                }
            }

            if ($tree->chance(30)) {
                $node['n'.$tree->int(10, 99)] = 't'.$tree->int(100, 999);
            }

            return $node;
        }

        foreach ($node as $key => $child) {
            $node[$key] = $this->generatedMutate($tree, $registered, [...$segments, (string) $key], $child, $locks);
        }

        return $node;
    }

    /**
     * Leaf by leaf under the entry: the tenant's written value where the tenant owns the leaf (nearest declaration, no
     * lock), the central value everywhere else.
     *
     * @param  list<string>  $segments  the entry's
     * @param  array<string, \Splicewire\Beam\Config\Overrides\ConfigOverrideLock>  $locks
     * @param  array<string, mixed>  $runtime
     */
    private function assertGeneratedRuntime(\Splicewire\Beam\Config\RegisteredConfigSection $registered, array $central, array $segments, mixed $raw, array $locks, array $runtime, string $at): void
    {
        $pointer = \Splicewire\Beam\Config\JsonPointer::from($segments);
        $leaves = fn (mixed $v): array => is_array($v) && $v !== [] ? \Splicewire\Beam\Config\JsonPointer::leaves($v) : [''];
        [, $centralAt] = \Splicewire\Beam\Config\JsonPointer::get($central, $pointer);
        [$exists, $runtimeAt] = \Splicewire\Beam\Config\JsonPointer::get($runtime, $pointer);
        $this->assertTrue($exists, "{$at}: the entry's key is missing at runtime");

        $expected = [];

        foreach (array_unique([...$leaves($raw), ...$leaves($centralAt)]) as $leaf) {
            $owned = \Splicewire\Beam\Config\Overrides\ConfigTierBoundary::admits($registered, [...$segments, ...\Splicewire\Beam\Config\JsonPointer::segments($leaf)], ConfigTier::Tenant, $locks);
            $from = $owned ? $raw : $centralAt;
            [$has, $value] = $leaf === '' ? [true, $from] : \Splicewire\Beam\Config\JsonPointer::get(is_array($from) ? $from : [], $leaf);

            if ($has) {
                $expected[$leaf] = $value;
            }
        }

        $actual = [];

        foreach ($leaves($runtimeAt) as $leaf) {
            $actual[$leaf] = $leaf === '' ? $runtimeAt : \Splicewire\Beam\Config\JsonPointer::get($runtimeAt, $leaf)[1];
        }

        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, "{$at}: config() inside the tenant's hop, leaf by leaf under {$pointer}");
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} config() and the tenant row, inside `$tenant`'s hop */
    private function tenantView(string $root, string $tenant): array
    {
        $context = app(FakeTenancyContext::class);
        $context->enter($tenant);
        app(ConfigTenantOverlay::class)->apply();

        try {
            $rows = app(ConfigSectionAccess::class)->rows(auth()->user(), $root);

            return [config($root), json_decode((string) json_encode($rows[0]->toArray()), true)];
        } finally {
            app(ConfigTenantOverlay::class)->revert();
            $context->leave();
        }
    }

    /* ---- root ab22fd59: a TENANT-tier write that has nowhere safe to go is refused, a tenant-tier read finds nothing,
     * and central is byte-untouched. SQLite has no search path, so it CANNOT show the `<tenant schema>,public`
     * fall-through: beam-tenancy's PG lane proves that hazard. Here, the same-target cases are the real collision on
     * SQLite (the tenant connection reaching central's own file). The missing-table case shows only the refusal and the
     * empty read. Before the guard, SQLite answered it with a 500, never a central write. ---- */

    /** What the tenant tier READS inside tenant a's hop: its scope, its versions, its published layers, and its row. */
    private function tenantReads(): array
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');

        try {
            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);

            return [$store->scope('tpanels'), $store->versionsOf('tpanels'), $store->publishedLayers(), $store->publishedToken()];
        } finally {
            $context->leave();
        }
    }

    private function assertTenantReadsNothing(string $because): void
    {
        [$scope, $versions, $layers, $token] = $this->tenantReads();
        $this->assertNull($scope, "{$because}: the tenant tier finds no scope (never central's)");
        $this->assertSame([], $versions, "{$because}: and lists no versions (never the operator's history)");
        $this->assertSame([], $layers, $because);
        $this->assertSame('none', $token, $because);

        $row = $this->set('/review', true, 'a')->assertStatus(422);
        $this->assertStringContainsString('refused', (string) $row->json('message'), $because);
    }

    /** Row count and a checksum of every row of central's store and version tables. */
    private function centralFingerprint(): array
    {
        $fingerprint = [];

        foreach (['config_scopes', 'config_overrides', 'versions'] as $name) {
            $rows = DB::connection('central')->table(Beam::table($name))->get()->map(fn ($row): array => (array) $row)->sortBy(fn (array $row): string => json_encode($row))->values()->all();
            $fingerprint[$name] = [count($rows), md5((string) json_encode($rows))];
        }

        return $fingerprint;
    }

    /** An operator override, so central's tables hold rows a stray tenant write could join or overwrite. */
    private function operatorBaseline(): array
    {
        $this->as('operator');
        $this->set('/theme', 'dark')->assertOk();
        $this->publish()->assertOk();
        $this->as('tenant-admin');

        return $this->centralFingerprint();
    }

    private function assertRefusedAsUnsafe(TestResponse $response, string $because): void
    {
        $message = (string) $response->assertStatus(422)->json('message');
        $this->assertStringContainsString('A tenant-tier config write was refused and nothing was written', $message);
        $this->assertStringContainsString($because, $message);
        $this->assertStringContainsString('tenants:migrate', $message);
        $this->assertStringContainsString('pools:migrate', $message);
    }

    public function test_a_tenant_write_is_refused_when_the_tenants_own_storage_lacks_the_store_and_central_is_byte_untouched(): void
    {
        $before = $this->operatorBaseline();

        // Only the VERSIONS table missing still refuses: all three tables are the store's (versions come from a separate
        // migration).
        Schema::connection('tenant_a')->drop(Beam::table('versions'));
        $this->assertRefusedAsUnsafe($this->set('/review', true, 'a'), "lacks the store's tables `".Beam::table('versions').'`');

        foreach (['config_scopes', 'config_overrides'] as $name) {
            Schema::connection('tenant_a')->drop(Beam::table($name));
        }

        foreach (['save-draft' => fn () => $this->set('/review', true, 'a'), 'publish' => fn () => $this->publish('a'), 'discard-draft' => fn () => $this->op('discard-draft', ['root' => 'tpanels'], 'a')] as $op => $call) {
            $this->assertRefusedAsUnsafe($call(), "lacks the store's tables `".Beam::table('config_scopes').'`');
        }

        // The restore op resolves its version through the (now empty) tenant scope and refuses first; the store's own
        // restore() refuses on the guard.
        $this->op('restore', ['root' => 'tpanels', 'version' => '1'], 'a')->assertStatus(422);
        $context = app(FakeTenancyContext::class);
        $context->enter('a');

        try {
            app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->restore('tpanels', 'any');
            $this->fail('restore() wrote on a tenant with no store of its own');
        } catch (\Illuminate\Validation\ValidationException $refusal) {
            $this->assertStringContainsString("lacks the store's tables", $refusal->getMessage());
        } finally {
            $context->leave();
        }

        $this->assertTenantReadsNothing('missing tables');
        $this->assertSame($before, $this->centralFingerprint(), "central's store is byte-untouched");
        $this->assertSame('dark', $this->probe('b')['theme'], 'every tenant still sees only the operator\'s value');
        $this->assertSame('dark', $this->probe('a')['theme'], 'and so does the refused tenant');
    }

    public function test_a_tenant_write_is_refused_when_the_tenant_connection_resolves_to_centrals_own_database(): void
    {
        $before = $this->operatorBaseline();

        // A tenant connection pointed at central's own database (a misconfiguration, or a pool resolved onto central's
        // schema): before the guard, the tenant's draft lands in central's operator rows.
        config()->set('database.connections.tenant_a.database', self::$files['central']);
        DB::purge('tenant_a');

        $this->assertRefusedAsUnsafe($this->set('/review', true, 'a'), "resolves to central's own database and schema");
        // READS too (review 05:34Z): before, the tenant tier read central's operator scope and history as its own.
        $this->assertTenantReadsNothing('same target');
        $this->assertSame($before, $this->centralFingerprint(), "central's store is byte-untouched");
        $this->assertFalse($this->probe('b')['review'], 'no tenant sees the refused value');
    }

    public function test_a_tenant_write_is_refused_when_central_is_spelled_by_url_and_the_tenant_by_path(): void
    {
        $before = $this->operatorBaseline();

        // Central's connection spelled as a `url` whose RAW `database` key says something else (review 05:34Z): only the
        // live, parsed connection shows that the tenant's file IS central's.
        config()->set('database.connections.central', [
            // Laravel's parser keeps an absolute sqlite path from four slashes (`sqlite:////abs/path`).
            'driver' => 'sqlite', 'url' => 'sqlite:///'.self::$files['central'], 'database' => 'not-the-file', 'prefix' => '', 'foreign_key_constraints' => false,
        ]);
        DB::purge('central');
        $this->assertSame(realpath(self::$files['central']), realpath((string) DB::connection('central')->getConfig('database')), 'the url is what the live connection uses');

        config()->set('database.connections.tenant_a.database', self::$files['central']);
        DB::purge('tenant_a');

        $this->assertRefusedAsUnsafe($this->set('/review', true, 'a'), "resolves to central's own database and schema");
        $this->assertSame($before, $this->centralFingerprint(), "central's store is byte-untouched");
    }

    public function test_the_tenant_tier_asked_for_with_no_tenant_active_is_refused_and_writes_nothing(): void
    {
        $before = $this->operatorBaseline();
        // Outside a tenant the default connection is a real host's central one.
        DB::setDefaultConnection('central');

        try {
            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);

            foreach (['saveDraft' => fn () => $store->saveDraft('tpanels', [['pointer' => '/review', 'op' => 'set', 'value' => true]]), 'publish' => fn () => $store->publish('tpanels'), 'discardDraft' => fn () => $store->discardDraft('tpanels')] as $method => $call) {
                try {
                    $call();
                    $this->fail("{$method}: the tenant tier wrote with no tenant active");
                } catch (\Illuminate\Validation\ValidationException $refusal) {
                    $this->assertStringContainsString('no tenant is active', $refusal->getMessage(), $method);
                }
            }
        } finally {
            DB::setDefaultConnection('testing');
        }

        $this->assertSame($before, $this->centralFingerprint(), "central's store is byte-untouched");
    }

    public function test_the_tenant_target_answer_is_asked_once_per_tenant_frame_and_afresh_in_the_next(): void
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        $overlay = app(ConfigTenantOverlay::class);
        $checks = 0;
        DB::listen(function ($query) use (&$checks): void {
            $checks += str_contains($query->sql, 'sqlite_master') || str_contains($query->sql, 'current_schema') ? 1 : 0;
        });

        try {
            // The hop's own apply() asks first (its published layers), and pays the table checks.
            $overlay->apply();
            $this->assertGreaterThan(0, $checks, 'the frame\'s first ask checks the tables');
            $first = $checks;

            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
            $this->assertNull($store->tenantTargetProblem());
            $this->assertNull(app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->tenantTargetProblem(), 'a forTier() clone shares the answer');
            $store->publishedLayers();
            $store->scope('tpanels');
            $this->assertSame($first, $checks, 'every later ask in the same frame runs no table check');

            // The next frame asks afresh: a table dropped between hops is seen.
            $overlay->revert();
            Schema::connection('tenant_a')->drop(Beam::table('versions'));
            $overlay->apply();
            $this->assertStringContainsString("lacks the store's tables `".Beam::table('versions').'`', (string) $store->tenantTargetProblem());
        } finally {
            $overlay->revert();
            $context->leave();
        }
    }

    /**
     * Review 05:46Z: with NO ladder bootstrapper (so the overlay never applies or reverts), a cached "ok" must not survive
     * a tenancy end + re-initialize for the same tenant after its table is dropped. stancl's own events clear it (core
     * listens by event NAME, so they are dispatched here by name; stancl itself is not a core dependency). A new PDO for the
     * connection starts with no answer, since the cache is keyed by the PDO object.
     */
    public function test_a_cached_ok_does_not_survive_a_tenancy_transition_or_a_new_pdo_without_the_bootstrapper(): void
    {
        $context = app(FakeTenancyContext::class);
        $write = fn () => app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->saveDraft('tpanels', [['pointer' => '/review', 'op' => 'set', 'value' => true]]);

        // 1. stancl's transitions, same PDO throughout.
        $context->enter('a');
        event('Stancl\Tenancy\Events\TenancyInitialized');
        $this->assertNull(app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->tenantTargetProblem(), 'ok, and cached');
        $context->leave();
        event('Stancl\Tenancy\Events\TenancyEnded');

        Schema::connection('tenant_a')->drop(Beam::table('versions'));

        $context->enter('a');
        event('Stancl\Tenancy\Events\TenancyInitialized');

        try {
            $write();
            $this->fail('a stale cached ok let the write through after the table was dropped');
        } catch (\Illuminate\Validation\ValidationException $refusal) {
            $this->assertStringContainsString("lacks the store's tables `".Beam::table('versions').'`', $refusal->getMessage());
        } finally {
            $context->leave();
            event('Stancl\Tenancy\Events\TenancyEnded');
        }

        // 2. No events at all, but a NEW PDO (the connection purged and reconnected): no answer is inherited.
        $context->enter('b');
        $this->assertNull(app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->tenantTargetProblem(), 'b ok, and cached on this PDO');
        Schema::connection('tenant_b')->drop(Beam::table('versions'));
        DB::purge('tenant_b');

        try {
            $this->assertStringContainsString("lacks the store's tables", (string) app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->tenantTargetProblem(), 'the new PDO asks afresh');
        } finally {
            $context->leave();
        }
    }

    /** config-sections 09: register the extend fixture (`tcatalog`) for the tests that need it. */
    private function withCatalog(): void
    {
        config()->set('tcatalog', require self::FIXTURES.'/package/tcatalog.php');
        app(ConfigSectionRegistry::class)->section(TenantCatalogSection::class, owner: 'acme/tcatalog', packageFile: realpath(self::FIXTURES.'/package/tcatalog.php'));
    }

    private function setCatalog(string $pointer, mixed $value, string $tenant): TestResponse
    {
        return $this->op('save-draft', ['root' => 'tcatalog', 'pointer' => $pointer, 'op' => 'set', 'value' => json_encode($value)], $tenant);
    }

    public function test_an_extend_entry_a_tenant_adds_applies_beside_the_platforms_entries_in_its_hop_only(): void
    {
        $this->withCatalog();
        $this->as('tenant-admin');
        $this->setCatalog('/catalog/mine', 'tenant model', 'a')->assertOk();
        $this->op('publish', ['root' => 'tcatalog'], 'a')->assertOk();

        $this->assertSame(['floor' => 'platform floor', 'mine' => 'tenant model'], $this->probe('a', 'tcatalog')['catalog'], 'the tenant adds an entry; the platform entry stays');
        $this->assertSame(['floor' => 'platform floor'], $this->probe('b', 'tcatalog')['catalog'], 'tenant B never sees it');
        $this->assertSame(['floor' => 'platform floor'], config('tcatalog.catalog'), 'the process holds the central value between hops');
    }

    public function test_a_platform_entry_added_after_a_tenants_extend_entry_wins_in_that_tenants_hop(): void
    {
        // TenantModelCatalog's floor-wins merge (array_merge($tenant, $floor)): a platform model added LATER, under the
        // same id a tenant already added, reaches the tenant. Extend is enforced at APPLY, not only at write.
        $this->withCatalog();
        $this->as('tenant-admin');
        $this->setCatalog('/catalog/shared', 'tenant version', 'a')->assertOk();
        $this->setCatalog('/catalog/mine', 'tenant model', 'a')->assertOk();
        $this->op('publish', ['root' => 'tcatalog'], 'a')->assertOk();
        $this->assertSame('tenant version', $this->probe('a', 'tcatalog')['catalog']['shared'], 'before the platform adds it, the tenant entry applies');

        config()->set('tcatalog.catalog.shared', 'platform version');

        $catalog = $this->probe('a', 'tcatalog')['catalog'];
        $this->assertSame('platform version', $catalog['shared'], 'the platform entry wins over the tenant entry it now collides with');
        $this->assertSame('tenant model', $catalog['mine'], "the tenant's other entries still apply");
        $this->assertSame('platform floor', $catalog['floor']);
    }

    public function test_a_non_extend_key_still_lets_the_tenant_replace_the_platforms_entry(): void
    {
        $this->withCatalog();
        $this->as('tenant-admin');
        $this->setCatalog('/routes/a', 'tenant A route', 'a')->assertOk();
        $this->op('publish', ['root' => 'tcatalog'], 'a')->assertOk();

        $this->assertSame('tenant A route', $this->probe('a', 'tcatalog')['routes']['a'], 'replace is unchanged: the tenant value wins');
        $this->assertSame('A', $this->probe('b', 'tcatalog')['routes']['a']);
    }

    public function test_the_guard_is_public_so_a_caller_naming_its_own_connection_asks_it_of_that_connection(): void
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');

        try {
            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
            $store->guardTenantTarget('tenant_a');
            $this->addToAssertionCount(1);

            $this->expectException(\Illuminate\Validation\ValidationException::class);
            $this->expectExceptionMessage("resolves to central's own database and schema");
            $store->guardTenantTarget('central');
        } finally {
            $context->leave();
        }
    }

    /*
     * config-sections ticket 09: the tenant layer's two extension points, contribute() and TenantConfigLayerLaid. Opt-in
     * and inert: with no contributor and no listener, apply() is exactly what it was.
     */

    private static function entry(string $pointer, mixed $value): ConfigOverrideEntry
    {
        return new ConfigOverrideEntry($pointer, ConfigOverrideOp::Set, $value, false, 'contributed', null, null);
    }

    /** Inside tenant `$id`'s hop: apply, run `$inside`, revert. */
    private function hop(string $id, callable $inside): mixed
    {
        $context = app(FakeTenancyContext::class);
        $context->enter($id);

        try {
            app(ConfigTenantOverlay::class)->apply();

            return $inside();
        } finally {
            app(ConfigTenantOverlay::class)->revert();
            $context->leave();
        }
    }

    public function test_with_no_contributor_and_no_listener_apply_takes_the_old_path_and_dispatches_nothing(): void
    {
        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();

        // The contributed path refuses to run: with none registered, apply() must never reach it.
        $this->app->instance(ConfigTenantOverlay::class, new class($this->app) extends ConfigTenantOverlay
        {
            protected function layContributed(\Illuminate\Contracts\Config\Repository $config, array $published): void
            {
                throw new \LogicException('the contributed path ran with no contributor');
            }
        });
        Event::fake([TenantConfigLayerLaid::class]);

        $this->assertSame('pink', $this->hop('a', fn () => config('tpanels.theme')));
        $this->assertSame('light', config('tpanels.theme'));
        Event::assertNotDispatched(TenantConfigLayerLaid::class);
    }

    public function test_a_contributors_entries_are_laid_in_the_hop_beside_the_published_ones_and_reverted_with_them(): void
    {
        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();
        $seen = null;
        app(ConfigTenantOverlay::class)->contribute(function (array $layers) use (&$seen): array {
            $seen = [array_keys($layers['tpanels'] ?? []), config('tpanels.theme')];
            $layers['tpanels']['/review'] = self::entry('/review', true);

            return $layers;
        });

        $this->assertSame(['pink', true], $this->hop('a', fn () => [config('tpanels.theme'), config('tpanels.review')]));
        $this->assertSame([['/theme'], 'light'], $seen, 'it receives the restricted published layers, with central in the repository');
        $this->assertSame(['light', false], [config('tpanels.theme'), config('tpanels.review')], 'both revert together');
    }

    public function test_a_contributor_runs_again_on_the_refresh_after_a_tenant_publish_mid_hop(): void
    {
        app(ConfigTenantOverlay::class)->contribute(function (array $layers): array {
            $layers['tpanels']['/review'] = self::entry('/review', true);

            return $layers;
        });

        $this->hop('a', function (): void {
            $this->assertTrue(config('tpanels.review'));
            $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
            $store->saveDraft('tpanels', [['pointer' => '/theme', 'op' => 'set', 'value' => 'pink']]);
            $store->publish('tpanels');

            $this->assertSame(['pink', true], [config('tpanels.theme'), config('tpanels.review')], "the publish's refresh re-applies the contributor too");
        });
    }

    public function test_a_contributor_is_never_called_when_the_tenant_store_read_fails(): void
    {
        // A read that FAILS (not a tenant with no store yet, which reads as nothing published and still runs contributors).
        $this->app->instance(ConfigOverrideStore::class, $this->app->make(UnreadableTenantStore::class));
        $called = false;
        app(ConfigTenantOverlay::class)->contribute(function (array $layers) use (&$called): array {
            $called = true;
            $layers['tpanels']['/review'] = self::entry('/review', true);

            return $layers;
        });

        $this->assertFalse($this->hop('a', fn () => config('tpanels.review')), 'central, never a contributed value');
        $this->assertFalse($called);
    }

    public function test_a_throwing_contributor_or_a_throwing_lay_leaves_the_hop_on_the_published_layer(): void
    {
        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();
        $overlay = app(ConfigTenantOverlay::class);
        $overlay->contribute(fn (array $layers): array => throw new \RuntimeException('contributor failed'));

        $this->assertSame('pink', $this->hop('a', fn () => config('tpanels.theme')), 'a throwing contributor is skipped');

        // A contributor that answers a layer lay() cannot write (an entry that is no ConfigOverrideEntry), after a valid one.
        $overlay->contribute(function (array $layers): array {
            $layers['tpanels']['/review'] = self::entry('/review', true);
            $layers['tpanels']['/zz-not-an-entry'] = 'not an entry';

            return $layers;
        });

        $inside = $this->hop('a', fn () => [config('tpanels.theme'), config('tpanels.review')]);
        $this->assertSame(['pink', false], $inside, 'the failed lay is undone and the published layer alone is laid');
        $this->assertSame(['light', false], [config('tpanels.theme'), config('tpanels.review')], 'central intact after the hop');
    }

    public function test_the_laid_event_names_roots_and_pointers_only_at_the_hop_and_on_a_mid_hop_operator_re_sync(): void
    {
        $this->as('tenant-admin');
        $this->set('/theme', 'pink', 'a')->assertOk();
        $this->publish('a')->assertOk();
        $events = [];
        Event::listen(TenantConfigLayerLaid::class, function (TenantConfigLayerLaid $event) use (&$events): void {
            $events[] = $event->pointers;
        });

        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        app(ConfigTenantOverlay::class)->apply();
        $this->assertSame([['tpanels' => ['/theme']]], $events, 'at the hop: where, never what');

        $context->leave();
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->saveDraft('tpanels', [['pointer' => '/review', 'op' => 'set', 'value' => true]]);
        $context->enter('a');
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->publish('tpanels');
        app(ConfigOverlay::class)->recheckNow();
        app(ConfigOverlay::class)->sync();

        $this->assertCount(2, $events, 'the operator re-sync re-laid the tenant layer and said so');
        $this->assertSame(['tpanels' => ['/theme']], $events[1]);
        $this->assertStringNotContainsString('pink', json_encode($events), 'no value in the payload');

        app(ConfigTenantOverlay::class)->revert();
        $context->leave();
    }
}
