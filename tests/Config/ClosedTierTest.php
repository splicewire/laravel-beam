<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Tests\Fixtures\Config\ClosedTierSection;
use Splicewire\Beam\Tests\Fixtures\Config\FakeTenancyContext;
use Splicewire\Beam\Tests\Fixtures\Config\OpenTierSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 17: a package closes a key to a tier (`#[ConfigKey(closed: [ConfigTier::Operator])]`). The operator's
 * draft there is refused with the reason, an operator row stored before the package closed the key never applies, the
 * tenant can still override it, and the Config area's provenance shows `closed`. Wiring as
 * {@see SecretInListItemsTenantTest}: central and one tenant database, the fake tenancy port. Every value here is FAKE.
 */
class ClosedTierTest extends TestCase
{
    private const ROOT = 'qa.closed';

    private const HOST = [
        'api_key' => 'fake-closed-host-key',
        'creds' => ['account' => 'fake-closed-host-acct', 'label' => 'host'],
        'sealed' => ['account' => 'fake-sealed-host-acct', 'label' => 'host'],
        'region' => 'eu',
        'audit_only' => 'host',
        'accounts' => [['account' => 'fake-list-host-acct', 'label' => 'host']],
    ];

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
            $stem = tempnam(sys_get_temp_dir(), "beam-cs17-{$connection}-");
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
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->declare(ClosedTierSection::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (self::$files as $file) {
            @unlink($file);
        }
    }

    /**
     * Register `$section` under the root in a fresh registry, as a host booting a new package version would: the host
     * config is reset, the store and overlays are rebuilt, and the operator overlay syncs.
     *
     * @param  class-string  $section
     */
    private function declare(string $section): void
    {
        config([self::ROOT => self::HOST]);
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);

        foreach ([ConfigOverrideStore::class, ConfigOverlay::class, ConfigTenantOverlay::class, ConfigSectionReader::class] as $singleton) {
            $this->app->forgetInstance($singleton);
        }

        app(ConfigSectionRegistry::class)->section($section, owner: 'qa/fixture');
        app(ConfigOverlay::class)->sync();
    }

    private function operator(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    private function asTenant(callable $act): mixed
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');

        try {
            return $act(app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant));
        } finally {
            $context->leave();
        }
    }

    /** @return array<string, mixed> config('qa.closed') inside tenant a's hop */
    private function tenantView(): array
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');
        app(ConfigTenantOverlay::class)->apply();

        try {
            return config(self::ROOT);
        } finally {
            app(ConfigTenantOverlay::class)->revert();
            $context->leave();
        }
    }

    /** The refusal's errors, as JSON; fails the test when `$write` is not refused. */
    private function refusedMessage(callable $write): string
    {
        try {
            $write();
        } catch (ValidationException $refused) {
            return (string) json_encode($refused->errors(), JSON_UNESCAPED_SLASHES);
        }

        $this->fail('the write was not refused');
    }

    /** @return array<string, ConfigKeyData> the reader's keys, by pointer */
    private function keys(): array
    {
        $data = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get(self::ROOT));
        $keys = [];

        foreach ($data->keys as $key) {
            $keys[$key->pointer] = $key;
        }

        return $keys;
    }

    public function test_the_declaration_walk_reads_closed_and_closure_is_sticky(): void
    {
        $this->assertTrue(ClosedTierSection::keyDeclaration(['api_key'])->closedTo(ConfigTier::Operator));
        $this->assertTrue(ClosedTierSection::keyDeclaration(['api_key'])->opensTo(ConfigTier::Tenant), 'closed to the operator only');
        $this->assertTrue(ClosedTierSection::keyDeclaration(['creds', 'account'])->closedTo(ConfigTier::Operator));
        $this->assertFalse(ClosedTierSection::keyDeclaration(['creds', 'label'])->closedTo(ConfigTier::Operator), 'control: an open sibling');
        $this->assertTrue(ClosedTierSection::keyDeclaration(['sealed', 'label'])->closedTo(ConfigTier::Operator), 'a child under a closed object stays closed');
        $this->assertTrue(ClosedTierSection::keyDeclaration(['audit_only'])->closedTo(ConfigTier::Tenant));
        $this->assertTrue(ClosedTierSection::keyDeclaration(['audit_only'])->opensTo(ConfigTier::Operator));
    }

    public function test_an_operator_draft_at_a_closed_key_is_refused_with_the_reason(): void
    {
        $message = $this->refusedMessage(fn () => $this->operator()->saveDraft(self::ROOT, [['pointer' => '/api_key', 'op' => 'set', 'value' => 'fake-closed-op-key']]));

        $this->assertStringContainsString('/api_key is closed to the operator tier by its package', $message);
        $this->assertStringNotContainsString('fake-closed-op-key', $message, 'the refusal does not echo the value');
    }

    public function test_an_operator_draft_below_a_closed_object_is_refused(): void
    {
        $message = $this->refusedMessage(fn () => $this->operator()->saveDraft(self::ROOT, [['pointer' => '/sealed/label', 'op' => 'set', 'value' => 'op']]));

        $this->assertStringContainsString('/sealed/label is closed to the operator tier', $message);
    }

    public function test_control_an_operator_draft_at_an_open_key_is_accepted(): void
    {
        $this->operator()->saveDraft(self::ROOT, [['pointer' => '/region', 'op' => 'set', 'value' => 'us']]);
        $this->operator()->publish(self::ROOT);
        $this->declare(ClosedTierSection::class);

        $this->assertSame('us', config(self::ROOT.'.region'));
    }

    public function test_an_operator_parent_write_that_changes_a_closed_child_is_refused(): void
    {
        $message = $this->refusedMessage(fn () => $this->operator()->saveDraft(self::ROOT, [['pointer' => '/creds', 'op' => 'set', 'value' => [
            'account' => 'fake-closed-op-acct', 'label' => 'op',
        ]]]));

        $this->assertStringContainsString('/creds/account is closed to the operator tier', $message);
        $this->assertStringContainsString('inside a write to /creds', $message);
    }

    public function test_an_operator_parent_write_that_resends_a_closed_child_inherits_it(): void
    {
        $this->operator()->saveDraft(self::ROOT, [['pointer' => '/creds', 'op' => 'set', 'value' => [
            'account' => self::HOST['creds']['account'], 'label' => 'op',
        ]]]);
        $this->operator()->publish(self::ROOT);

        // The host changes the closed child after the operator published: the operator row does not pin the old value.
        $host = self::HOST;
        $host['creds']['account'] = 'fake-closed-host-acct-2';
        $this->declare(ClosedTierSection::class);
        config([self::ROOT => $host]);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigOverlay::class)->sync();

        $this->assertSame('op', config(self::ROOT.'.creds.label'));
        $this->assertSame('fake-closed-host-acct-2', config(self::ROOT.'.creds.account'), 'inherited, not pinned');
    }

    public function test_an_operator_row_stored_before_the_package_closed_the_key_never_applies(): void
    {
        $this->declare(OpenTierSection::class);
        $this->operator()->saveDraft(self::ROOT, [
            ['pointer' => '/api_key', 'op' => 'set', 'value' => 'fake-closed-op-key'],
            ['pointer' => '/sealed', 'op' => 'set', 'value' => ['account' => 'fake-sealed-op-acct', 'label' => 'op']],
            ['pointer' => '/creds', 'op' => 'set', 'value' => ['account' => 'fake-closed-op-acct', 'label' => 'op']],
            ['pointer' => '/region', 'op' => 'set', 'value' => 'us'],
        ]);
        $this->operator()->publish(self::ROOT);
        $this->declare(OpenTierSection::class);
        $this->assertSame('fake-closed-op-key', config(self::ROOT.'.api_key'), 'precondition: the row applies while the key is open');
        $this->assertSame('fake-closed-op-acct', config(self::ROOT.'.creds.account'), 'precondition');

        $this->declare(ClosedTierSection::class);

        $this->assertSame(self::HOST['api_key'], config(self::ROOT.'.api_key'), 'a closed key: the row is dropped');
        $this->assertSame(self::HOST['sealed'], config(self::ROOT.'.sealed'), 'a closed object: the row is dropped');
        $this->assertSame(self::HOST['creds']['account'], config(self::ROOT.'.creds.account'), 'a closed child is cut out of an open entry');
        $this->assertSame('op', config(self::ROOT.'.creds.label'), 'the rest of that entry still applies');
        $this->assertSame('us', config(self::ROOT.'.region'), 'control: an open key still applies');
    }

    /**
     * Codex review, lane A (blocker 1): a list is one value (21), so a field closed inside its items closes the LIST key.
     * Before, the list key read open and the write side compared the list as one leaf, so a write at the list replaced
     * a closed field inside an item.
     */
    public function test_a_list_whose_items_close_a_field_is_closed_at_the_list(): void
    {
        $this->assertTrue(ClosedTierSection::keyDeclaration(['accounts'])->closedTo(ConfigTier::Operator));
        $this->assertTrue(ClosedTierSection::keyDeclaration(['accounts'])->opensTo(ConfigTier::Tenant), 'the item closes the operator only');
        $this->assertFalse(OpenTierSection::keyDeclaration(['accounts'])->closedTo(ConfigTier::Operator), 'control: open items');

        $message = $this->refusedMessage(fn () => $this->operator()->saveDraft(self::ROOT, [['pointer' => '/accounts', 'op' => 'set', 'value' => [
            ['account' => 'fake-list-op-acct', 'label' => 'op'],
        ]]]));
        $this->assertStringContainsString('/accounts is closed to the operator tier', $message);

        $keys = $this->keys();
        $this->assertSame([ConfigTier::Operator], $keys['/accounts']->closed);
        $this->assertFalse($keys['/accounts']->overridable);
    }

    public function test_an_operator_list_row_stored_before_its_items_closed_a_field_never_applies(): void
    {
        $this->declare(OpenTierSection::class);
        $this->operator()->saveDraft(self::ROOT, [['pointer' => '/accounts', 'op' => 'set', 'value' => [
            ['account' => 'fake-list-op-acct', 'label' => 'op'],
        ]]]);
        $this->operator()->publish(self::ROOT);
        $this->declare(OpenTierSection::class);
        $this->assertSame('fake-list-op-acct', config(self::ROOT.'.accounts.0.account'), 'precondition: the row applies while open');

        $this->declare(ClosedTierSection::class);

        $this->assertSame(self::HOST['accounts'], config(self::ROOT.'.accounts'));
    }

    /**
     * Codex review, lane A (blocker 2): a stored PARENT row must not erase a key closed below it, whether it nulls the
     * parent or replaces it with an object that leaves the closed key out.
     */
    public function test_a_stored_parent_null_does_not_erase_a_closed_child(): void
    {
        $this->declare(OpenTierSection::class);
        $this->operator()->saveDraft(self::ROOT, [['pointer' => '/creds', 'op' => 'null']]);
        $this->operator()->publish(self::ROOT);
        $this->declare(OpenTierSection::class);
        $this->assertNull(config(self::ROOT.'.creds'), 'precondition: the row applies while open');

        $this->declare(ClosedTierSection::class);

        $this->assertSame(self::HOST['creds'], config(self::ROOT.'.creds'), 'a null parent over a closed child is dropped');
    }

    public function test_a_stored_parent_set_that_omits_a_closed_child_inherits_it(): void
    {
        $this->declare(OpenTierSection::class);
        $this->operator()->saveDraft(self::ROOT, [
            ['pointer' => '/creds', 'op' => 'set', 'value' => ['label' => 'op']],
            ['pointer' => '/region', 'op' => 'set', 'value' => 'us'],
        ]);
        $this->operator()->publish(self::ROOT);
        $this->declare(OpenTierSection::class);
        $this->assertNull(config(self::ROOT.'.creds.account'), 'precondition: the object replaced the parent while open');

        $this->declare(ClosedTierSection::class);

        $this->assertSame(self::HOST['creds']['account'], config(self::ROOT.'.creds.account'), 'the closed child is inherited');
        $this->assertSame('op', config(self::ROOT.'.creds.label'), 'the rest of the row still applies');
        $this->assertSame('us', config(self::ROOT.'.region'), 'control');
    }

    public function test_the_tenant_hop_starts_from_the_host_value_when_the_operator_row_is_closed(): void
    {
        $this->declare(OpenTierSection::class);
        $this->operator()->saveDraft(self::ROOT, [['pointer' => '/api_key', 'op' => 'set', 'value' => 'fake-closed-op-key']]);
        $this->operator()->publish(self::ROOT);
        $this->declare(ClosedTierSection::class);

        $this->assertSame(self::HOST['api_key'], $this->tenantView()['api_key']);
    }

    public function test_the_tenant_can_still_override_an_operator_closed_key(): void
    {
        $this->asTenant(function (ConfigOverrideStore $tenant): void {
            $tenant->saveDraft(self::ROOT, [['pointer' => '/api_key', 'op' => 'set', 'value' => 'fake-closed-tenant-key']]);
            $tenant->publish(self::ROOT);
        });

        $this->assertSame('fake-closed-tenant-key', $this->tenantView()['api_key']);
        $this->assertSame(self::HOST['api_key'], config(self::ROOT.'.api_key'), 'central is untouched');
    }

    public function test_a_tenant_draft_at_a_key_closed_to_the_tenant_is_refused(): void
    {
        $message = $this->refusedMessage(fn () => $this->asTenant(fn (ConfigOverrideStore $tenant) => $tenant->saveDraft(self::ROOT, [['pointer' => '/audit_only', 'op' => 'set', 'value' => 'tenant']])));

        $this->assertStringContainsString('/audit_only is closed to the tenant tier by its package', $message);
    }

    public function test_a_lock_on_a_key_closed_to_the_tenant_is_refused(): void
    {
        $message = $this->refusedMessage(fn () => $this->operator()->lock(self::ROOT, '/audit_only', 'kept central'));

        $this->assertStringContainsString('closed to the tenant tier by its package', $message);
    }

    public function test_the_reader_shows_closed_and_an_operator_closed_key_is_not_overridable(): void
    {
        $keys = $this->keys();

        $this->assertSame([ConfigTier::Operator], $keys['/api_key']->closed);
        $this->assertFalse($keys['/api_key']->overridable, 'closed to the operator realm');
        $this->assertSame([ConfigTier::Operator], $keys['/creds/account']->closed);
        $this->assertSame([ConfigTier::Operator], $keys['/sealed/label']->closed, 'sticky');
        $this->assertSame([ConfigTier::Tenant], $keys['/audit_only']->closed);
        $this->assertTrue($keys['/audit_only']->overridable, 'open to the operator');
        $this->assertSame([], $keys['/region']->closed);
        $this->assertSame('["operator"]', json_encode($keys['/api_key']->toArray()['closed']), 'the wire carries the tier values the Config area reads');
        $this->assertTrue($keys['/region']->overridable);
    }

    public function test_the_tenant_realm_offers_an_operator_closed_key_and_hides_a_tenant_closed_one(): void
    {
        $context = app(FakeTenancyContext::class);
        $context->enter('a');

        try {
            $keys = $this->keys();
        } finally {
            $context->leave();
        }

        $this->assertArrayHasKey('/api_key', $keys);
        $this->assertTrue($keys['/api_key']->overridable, 'the tenant may override it');
        $this->assertArrayNotHasKey('/audit_only', $keys, 'closed to the tenant: not offered');
        $this->assertStringNotContainsString('fake-closed-host-key', (string) json_encode($keys), 'the secret stays redacted');
    }
}
