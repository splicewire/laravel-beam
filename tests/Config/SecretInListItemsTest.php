<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\RelayGroupSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 20, review round 4 (lead T20-R4-1530):
 * - a pointer through a LIST index never goes into a parent's `inherit`: unsetting an index-split secret leaves the item
 *   WITHOUT a secret, never with the tier below's secret at that index (another record's);
 * - the dedicated list cases: an empty list, a list of scalars, an index of 10 or more, a null secret in an item.
 *
 * The tenant-tier variant is {@see SecretInListItemsTenantTest}. Every value here is FAKE.
 */
class SecretInListItemsTest extends TestCase
{
    private const OP_SECRETS = ['fake-li-op', 'fake-li-10', 'fake-li-0', 'fake-li-at', 'fake-li-cred', 'fake-li-id10', 'fake-li-id2'];

    private static string $database;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $connection = ['driver' => 'sqlite', 'database' => self::$database, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.central', $connection);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-20e-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config(['qa.relaygroup' => self::host()]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(RelayGroupSection::class, owner: 'qa/fixture');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    /** @return array<string, mixed> the host's values: one relay at index 0 with the HOST's secret */
    private static function host(): array
    {
        return [
            'group' => ['relays' => [['host' => 'relay.host.test', 'password' => 'fake-li-host-0', 'creds' => ['a' => ['label' => 'host cred', 'token' => 'fake-li-host-cred']]]]],
            'byName' => ['a' => ['relays' => [['host' => 'a.host.test', 'password' => 'fake-li-host-a']]]],
            'holder' => ['primary' => ['host' => 'p.host.test', 'password' => 'fake-li-host-p']],
            'byId' => ['10' => ['host' => 'id10.host.test', 'password' => 'fake-li-host-10'], '2' => ['host' => 'id2.host.test', 'password' => 'fake-li-host-2']],
        ];
    }

    private function store(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    private function publish(array $ops): void
    {
        $this->store()->saveDraft('qa.relaygroup', $ops);
        $this->store()->publish('qa.relaygroup');
        // A later process boots from the host's values: the in-process config is put back to them before the overlay
        // lays the published layer again, so "the tier below" is really the host here.
        config(['qa.relaygroup' => self::host()]);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigOverlay::class)->sync();
        $this->assertNoSecretStored();
    }

    private function assertNoSecretStored(): void
    {
        $raw = (string) json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('config_scopes'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
        ]);

        foreach (self::OP_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "no secret value in a stored row, a scope or a version ({$secret})");
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function draft(): array
    {
        return $this->store()->scope('qa.relaygroup')->draft ?? [];
    }

    // ---- no index pointer in inherit (r4 HIGH) ----

    public function test_unsetting_an_index_split_secret_never_inherits_the_lower_tiers_secret_at_that_index(): void
    {
        $this->publish([['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'relay.op.test', 'password' => 'fake-li-op']]]]]);
        $this->assertNotContains('/relays/0/password', $this->draft()['/group']['inherit'] ?? [], 'no list-index pointer in the parent\'s inherit');

        $this->publish([['pointer' => '/group/relays/0/password', 'op' => 'unset']]);

        $this->assertSame('relay.op.test', config('qa.relaygroup.group.relays.0.host'));
        $this->assertNotSame('fake-li-host-0', config('qa.relaygroup.group.relays.0.password'), "the host's relay.host.test credential is never sent to relay.op.test");
        $this->assertNull(config('qa.relaygroup.group.relays.0.password'), 'removed, the item simply has no secret');
    }

    // ---- r5: the rule decides on the FULL pointer (lead T20-R5-1725) ----

    /** A set made AT a list item (its own pointer holds the index): its secret is not inherited either. */
    public function test_a_set_at_a_list_item_then_an_unset_of_its_secret_inherits_nothing(): void
    {
        $this->publish([['pointer' => '/group/relays/0', 'op' => 'set', 'value' => ['host' => 'relay.at.test', 'password' => 'fake-li-at']]]);
        $this->assertNotContains('/password', $this->draft()['/group/relays/0']['inherit'] ?? [], 'the op pointer holds an index: nothing is inherited');

        $this->publish([['pointer' => '/group/relays/0/password', 'op' => 'unset']]);

        $this->assertSame('relay.at.test', config('qa.relaygroup.group.relays.0.host'));
        $this->assertNull(config('qa.relaygroup.group.relays.0.password'), "never the host's index-0 secret");
    }

    /** A map nested INSIDE a list item (`/relays/0/creds`): the index is in the op's pointer, not in the relative part. */
    public function test_a_set_at_a_map_inside_a_list_item_then_an_unset_inherits_nothing(): void
    {
        $this->publish([['pointer' => '/group/relays/0/creds', 'op' => 'set', 'value' => ['a' => ['label' => 'op cred', 'token' => 'fake-li-cred']]]]);
        $this->assertNotContains('/a/token', $this->draft()['/group/relays/0/creds']['inherit'] ?? []);

        $this->publish([['pointer' => '/group/relays/0/creds/a/token', 'op' => 'unset']]);

        $this->assertNull(config('qa.relaygroup.group.relays.0.creds.a.token'), "never the host's index-0 credential");
    }

    /**
     * An import-shaped op whose own `inherit` names a pointer through a list index (Codex r4 HIGH): IGNORED. It is
     * not refilled from the tier below, so the host's index-0 secret is never copied up as this op's own encrypted override.
     */
    public function test_an_op_inherit_naming_a_list_index_pointer_is_ignored_and_copies_nothing_up(): void
    {
        $this->publish([['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'relay.import.test']]], 'inherit' => ['/relays/0/password']]]);

        $this->assertArrayNotHasKey('/group/relays/0/password', $this->draft(), "the host's secret was not split out as the op's own");
        $this->assertSame([], $this->draft()['/group']['inherit'] ?? [], 'nor kept in inherit');
        $this->assertNull(config('qa.relaygroup.group.relays.0.password'), 'the imported item has no secret');
    }

    // ---- list-ness comes from the DECLARATION (lead LIST-1745) ----

    public function test_a_list_index_is_read_from_the_declaration_not_from_the_digits(): void
    {
        $this->assertTrue(RelayGroupSection::throughListIndex(['group', 'relays', '0', 'password']), 'a declared list (DataCollectionOf)');
        $this->assertTrue(RelayGroupSection::throughListIndex(['group', 'relays', '0', 'creds', 'a', 'token']), 'a map inside a list item');
        $this->assertFalse(RelayGroupSection::throughListIndex(['group', 'relays']), 'the list itself, no index');
        $this->assertFalse(RelayGroupSection::throughListIndex(['byId', '10', 'password']), 'a MapValues key "10" is a stable name');
        $this->assertFalse(RelayGroupSection::throughListIndex(['byName', 'a', 'relays']), 'a map key, then the list itself');
        $this->assertTrue(RelayGroupSection::throughListIndex(['loose', '0', 'x']), 'an array with no item attribute: the numeric fallback');
        $this->assertTrue(RelayGroupSection::throughListIndex(['undeclared', '3']), 'an undeclared segment: the numeric fallback');
    }

    /** A MAP keyed by numeric ids (`/byId/10`) is a map: an unset of its secret inherits the host's, as any map does. */
    public function test_a_map_keyed_by_numeric_ids_inherits_normally(): void
    {
        $this->publish([['pointer' => '/byId', 'op' => 'set', 'value' => [
            '10' => ['host' => 'id10.op.test', 'password' => 'fake-li-id10'],
            '2' => ['host' => 'id2.op.test', 'password' => 'fake-li-id2'],
        ]]]);
        $this->assertContains('/10/password', $this->draft()['/byId']['inherit'] ?? [], 'a map key is inherited');

        $this->publish([['pointer' => '/byId/10/password', 'op' => 'unset']]);

        $this->assertSame('fake-li-host-10', config('qa.relaygroup.byId.10.password'), 'unset means inherit, for a map entry');
        $this->assertSame('fake-li-id2', config('qa.relaygroup.byId.2.password'), 'the other entry keeps its own');
    }

    // ---- the dedicated list cases (Codex r3 LOW) ----

    public function test_an_empty_list_is_stored_as_is_and_splits_nothing(): void
    {
        $this->publish([['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => []]]]);

        $this->assertSame([], array_values(array_filter(array_keys($this->draft()), fn (string $p): bool => str_starts_with($p, '/group/'))), 'no child entries');
        $this->assertSame([], config('qa.relaygroup.group.relays'));
    }

    public function test_a_list_of_scalars_splits_nothing(): void
    {
        $this->publish([['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [], 'tags' => ['blue', 'green']]]]);

        $this->assertSame(['/group'], array_keys($this->draft()), 'one entry, no children');
        $this->assertSame(['blue', 'green'], config('qa.relaygroup.group.tags'));
    }

    public function test_an_index_of_ten_or_more_is_split_like_any_other(): void
    {
        $relays = [];
        for ($i = 0; $i <= 10; $i++) {
            $relays[] = ['host' => "r{$i}.op.test", 'password' => $i === 10 ? 'fake-li-10' : ($i === 0 ? 'fake-li-0' : "fake-li-{$i}-x")];
        }

        $this->publish([['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => $relays]]]);

        $this->assertTrue($this->draft()['/group/relays/10/password']['secret'] ?? false, 'index 10 is its own encrypted entry');
        $this->assertSame(['fake-li-0', 'fake-li-10'], [config('qa.relaygroup.group.relays.0.password'), config('qa.relaygroup.group.relays.10.password')], 'each lands on its own record');
        $this->assertSame([], $this->draft()['/group']['inherit'] ?? [], 'and none of them is inherited');
    }

    /** A null secret in a list item is split too: an explicit-null secret entry, never a plain value (review r3 NIT). */
    public function test_a_null_secret_in_a_list_item_is_split_as_an_explicit_null(): void
    {
        $this->publish([['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'relay.op.test', 'password' => null]]]]]);

        $child = $this->draft()['/group/relays/0/password'] ?? null;
        $this->assertSame([true, 'set', '/group'], [$child['secret'] ?? null, $child['op'] ?? null, $child['via'] ?? null]);
        $this->assertArrayNotHasKey('value', $child, 'no plain value');
        $this->assertNull(config('qa.relaygroup.group.relays.0.password'), 'and the host\'s index-0 secret is not inherited');
    }
}
