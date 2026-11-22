<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tests\Fixtures\Config\FakeTenancyContext;
use Splicewire\Beam\Tests\Fixtures\Config\TenantRelaySection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 20, review round 4 (lead T20-R4-1530), the TENANT tier: unsetting a tenant's index-split secret never
 * fills that slot from the OPERATOR's list at the same index (another record's, and a secret the tenant must not get).
 * A lean copy of {@see ConfigTenantTierTest}'s wiring: central and one tenant database, the fake tenancy port.
 * Every value here is FAKE.
 */
class SecretInListItemsTenantTest extends TestCase
{
    /** @var array<string, string> */
    private static array $files = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');

        foreach (self::$files as $connection => $file) {
            $app['config']->set("database.connections.{$connection}", ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        }

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        foreach (['testing', 'central', 'tenant_a'] as $connection) {
            $stem = tempnam(sys_get_temp_dir(), "beam-cs20t-{$connection}-");
            @unlink($stem);
            self::$files[$connection] = $stem.'.sqlite';
            touch(self::$files[$connection]);
        }

        parent::setUp();

        foreach (['central', 'tenant_a'] as $connection) {
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

        $this->app->instance(FakeTenancyContext::class, new FakeTenancyContext);
        $this->app->instance(TenancyContext::class, app(FakeTenancyContext::class));

        config(['qa.trelay' => ['group' => ['relays' => [['host' => 'relay.host.test', 'password' => 'fake-lt-host-0']]]]]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        foreach ([ConfigOverrideStore::class, ConfigOverlay::class, ConfigTenantOverlay::class] as $singleton) {
            $this->app->forgetInstance($singleton);
        }
        app(ConfigSectionRegistry::class)->section(TenantRelaySection::class, owner: 'qa/fixture');
        app(ConfigOverlay::class)->sync();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (self::$files as $file) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed> config('qa.trelay') inside tenant a's hop */
    private function tenantView(): array
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        app(ConfigTenantOverlay::class)->apply();

        try {
            return config('qa.trelay');
        } finally {
            app(ConfigTenantOverlay::class)->revert();
            $context->leave();
        }
    }

    private function asTenant(callable $write): void
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');

        try {
            $write(app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant));
        } finally {
            $context->leave();
        }
    }

    public function test_unsetting_a_tenants_index_split_secret_never_inherits_the_operators_secret_at_that_index(): void
    {
        // The operator's own list on central: relay.op.test with the OPERATOR's secret at index 0.
        $operator = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
        $operator->saveDraft('qa.trelay', [['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'relay.op.test', 'password' => 'fake-lt-operator']]]]]);
        $operator->publish('qa.trelay');
        app(ConfigOverlay::class)->sync();

        // The tenant sets its own list (another relay), then removes that relay's secret.
        $this->asTenant(function (ConfigOverrideStore $tenant): void {
            $tenant->saveDraft('qa.trelay', [['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'relay.tenant.test', 'password' => 'fake-lt-tenant']]]]]);
            $tenant->publish('qa.trelay');
            $this->assertNotContains('/relays/0/password', $tenant->scope('qa.trelay')->draft['/group']['inherit'] ?? [], 'no list-index pointer in inherit');
            $tenant->saveDraft('qa.trelay', [['pointer' => '/group/relays/0/password', 'op' => 'unset']]);
            $tenant->publish('qa.trelay');
        });

        $view = $this->tenantView();
        $this->assertSame('relay.tenant.test', $view['group']['relays'][0]['host'] ?? null);
        $this->assertNotSame('fake-lt-operator', $view['group']['relays'][0]['password'] ?? null, "the operator's secret never reaches the tenant's relay");
        $this->assertNull($view['group']['relays'][0]['password'] ?? null, 'removed, the item has no secret');
    }
}
