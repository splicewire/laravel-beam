<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelayHostTokenSection;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelayRedeclared;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelaySection;
use Splicewire\Beam\Tests\Fixtures\Config\RelayGroupSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 19, review round 2 (lead T19-R2-1435, T19-R2b-1455):
 * - A LIST INDEX IS NOT A STABLE IDENTITY. A split child whose path below the parent passes through a list index is never
 *   kept by a parent op (dropped, as at base), and a null there never becomes an inherit. So no secret ever moves to
 *   another record when a record is deleted or the list reordered, and no host secret is pulled in by position.
 * - A kept child must still be ADMITTED: its current ceiling (and, for the tenant tier, an operator lock) must admit the
 *   saving tier, or it is dropped and the tier below applies.
 * - The review's LOWs: a non-secret null drops the secret below it; an empty-object ancestor still counts as present.
 *
 * Every value here is FAKE.
 */
class ParentSetKeepsSecretChildrenListsTest extends TestCase
{
    private const SECRETS = ['fake-lg-a', 'fake-lg-b', 'fake-op-token-1', 'fake-op-extra-1', 'fake-lg-tok'];

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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-19b-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config([
            'qa.relaygroup' => [
                'group' => ['relays' => [['host' => 'a.host.test', 'password' => 'fake-lg-host-0'], ['host' => 'b.host.test', 'password' => 'fake-lg-host-1']]],
                'byName' => ['x' => ['relays' => [['host' => 'x.host.test', 'password' => 'fake-lg-host-x']]]],
                'maybe' => ['relays' => []],
                'holder' => ['primary' => ['host' => 'p.host.test', 'password' => 'fake-lg-host-p']],
                'byId' => ['10' => ['host' => 'id10.host.test', 'password' => 'fake-lg-host-10']],
            ],
            'qa.deep' => ['relay' => [
                'deploy' => 'host-driver', 'host' => 'relay.host.test', 'token' => 'fake-host-token',
                'inner' => ['region' => 'eu', 'apiKey' => 'fake-host-api', 'core' => ['zone' => 'z1', 'pin' => 'fake-host-pin']],
                'extra' => ['note' => 'host note', 'extraKey' => 'fake-host-extra'],
            ]],
        ]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->registerSections(DeepRelaySection::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    private function registerSections(string $deep): void
    {
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(RelayGroupSection::class, owner: 'qa/fixture');
        app(ConfigSectionRegistry::class)->section($deep, owner: 'qa/fixture');
    }

    private function store(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    private function publish(string $root, array $ops): void
    {
        $this->store()->saveDraft($root, $ops);
        $this->store()->publish($root);
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

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "no secret value in a stored row, a scope or a version ({$secret})");
        }
    }

    /** @return array<string, mixed> the two operator relays A and B */
    private static function twoRelays(): array
    {
        return ['relays' => [['host' => 'a.op.test', 'password' => 'fake-lg-a'], ['host' => 'b.op.test', 'password' => 'fake-lg-b']]];
    }

    private function relay(int $i, string $key): mixed
    {
        return config("qa.relaygroup.group.relays.{$i}.{$key}");
    }

    // ---- a list index is not a stable identity ----

    public function test_deleting_the_first_of_two_relays_moves_no_secret_to_the_survivor(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => self::twoRelays()]]);

        // The form deletes relay A and sends relay B back as it read it: its secret as the reader's null.
        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'b.op.test', 'password' => null]]]]]);

        $this->assertSame('b.op.test', $this->relay(0, 'host'));
        $this->assertNotSame('fake-lg-a', $this->relay(0, 'password'), "relay A's credential never goes to relay B's server");
        $this->assertNull($this->relay(0, 'password'), 'a null at a list index stays an explicit null, as at base (B loses its secret, unchanged)');
    }

    public function test_reordering_two_relays_swaps_no_secret(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => self::twoRelays()]]);

        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [
            ['host' => 'b.op.test', 'password' => null], ['host' => 'a.op.test', 'password' => null],
        ]]]]);

        $this->assertSame(['b.op.test', 'a.op.test'], [$this->relay(0, 'host'), $this->relay(1, 'host')]);
        $this->assertNotSame('fake-lg-a', $this->relay(0, 'password'), "A's secret does not follow position 0 to B");
        $this->assertNotSame('fake-lg-b', $this->relay(1, 'password'), "B's secret does not follow position 1 to A");
    }

    public function test_a_null_at_a_list_index_with_nothing_stored_pulls_in_no_host_secret(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'new.op.test', 'password' => null]]]]]);

        $this->assertNotSame('fake-lg-host-0', $this->relay(0, 'password'), "the host's entry-0 secret is not inherited by position");
        $this->assertNull($this->relay(0, 'password'));
    }

    public function test_a_list_nested_in_a_map_entry_keeps_no_secret_by_position(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/byName/x', 'op' => 'set', 'value' => ['relays' => [['host' => 'x.op.test', 'password' => 'fake-lg-a']]]]]);

        $this->publish('qa.relaygroup', [['pointer' => '/byName', 'op' => 'set', 'value' => ['x' => ['relays' => [['host' => 'y.op.test', 'password' => null]]]]]]);

        $this->assertNotSame('fake-lg-a', config('qa.relaygroup.byName.x.relays.0.password'), "the old record's secret does not stay at position 0");
        $this->assertNull(config('qa.relaygroup.byName.x.relays.0.password'), 'a null at a list index stays an explicit null');
    }

    // ---- 19 r4: list-ness from the declaration, on the full pointer (lead LIST-1745, T20-R5) ----

    /** A MAP keyed by numeric ids is a map: its secret is KEPT on a parent re-save that leaves it out (Codex r3 MEDIUM). */
    public function test_a_map_entry_with_a_numeric_key_keeps_its_secret_on_a_parent_re_save(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => ['10' => ['host' => 'id10.op.test', 'password' => 'fake-lg-a']]]]);

        $this->publish('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => ['10' => ['host' => 'id10.op-2.test']]]]);

        $this->assertSame('/byId', $this->store()->scope('qa.relaygroup')->draft['/byId/10/password']['via'] ?? null, 'kept (a map key is a stable name)');
        $this->assertSame('fake-lg-a', config('qa.relaygroup.byId.10.password'));
    }

    /** A numeric-keyed MAP entry sent back with the reader's redaction null keeps its secret (a map, by declaration). */
    public function test_a_numeric_map_key_with_the_redaction_null_keeps_its_secret(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => ['10' => ['host' => 'id10.op.test', 'password' => 'fake-lg-a']]]]);

        $this->publish('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => ['10' => ['host' => 'id10.op.test', 'password' => null]]]]);

        $this->assertSame('fake-lg-a', config('qa.relaygroup.byId.10.password'));
    }

    /**
     * Map keys "0" and "1" make a PHP LIST in shape, which encodes as a JSON array; the section schema (an object) refuses
     * the draft. PRE-EXISTING, not this ticket's: such a map cannot be saved at all, so keep-or-drop never arises. Declare
     * keys that are not exactly 0..n-1 (stable names). Nothing is stored in plain on the refusal.
     */
    public function test_map_keys_zero_and_one_are_refused_by_the_schema_as_a_json_array(): void
    {
        try {
            $this->store()->saveDraft('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => [
                '0' => ['host' => 'id0.op.test', 'password' => 'fake-lg-a'],
                '1' => ['host' => 'id1.op.test', 'password' => 'fake-lg-b'],
            ]]]);
            $this->fail('a 0..n-1 keyed map was accepted: if the schema learns to take it, assert the keep instead');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('does not validate against its section schema', json_encode($refused->errors(), JSON_UNESCAPED_SLASHES));
        }

        $this->assertNoSecretStored();
    }

    /** Numeric keys at TWO levels (`/byId/10/creds/7/token`): both secrets are kept on the outer parent re-save. */
    public function test_numeric_map_keys_at_two_levels_keep_their_secrets(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => [
            '10' => ['host' => 'id10.op.test', 'password' => 'fake-lg-a', 'creds' => ['7' => ['label' => 'seven', 'token' => 'fake-lg-tok']]],
        ]]]);

        $this->publish('qa.relaygroup', [['pointer' => '/byId', 'op' => 'set', 'value' => [
            '10' => ['host' => 'id10.op-2.test', 'creds' => ['7' => ['label' => 'seven-2']]],
        ]]]);

        $this->assertSame(['fake-lg-a', 'fake-lg-tok'], [config('qa.relaygroup.byId.10.password'), config('qa.relaygroup.byId.10.creds.7.token')]);
    }

    /** An op AT a list item that sends the reader's null: an explicit null, never the tier below's entry-N secret. */
    public function test_an_op_at_a_list_item_sending_null_keeps_nothing_and_inherits_nothing(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/group/relays/0', 'op' => 'set', 'value' => ['host' => 'a.op.test', 'password' => 'fake-lg-a']]]);

        $this->publish('qa.relaygroup', [['pointer' => '/group/relays/0', 'op' => 'set', 'value' => ['host' => 'a.op.test', 'password' => null]]]);

        $this->assertNotSame('fake-lg-a', $this->relay(0, 'password'), 'not kept: the index is in the op pointer');
        $this->assertNull($this->relay(0, 'password'), "and not the host's index-0 secret");
    }

    /** An import naming a list-index leaf in its inherit, over a stored child at that index: ignored, nothing kept. */
    public function test_an_import_naming_a_list_index_leaf_neither_keeps_nor_copies_a_secret(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => self::twoRelays()]]);

        $this->publish('qa.relaygroup', [['pointer' => '/group', 'op' => 'set', 'value' => ['relays' => [['host' => 'b.op.test']]], 'inherit' => ['/relays/0/password']]]);

        $this->assertArrayNotHasKey('/group/relays/0/password', $this->store()->scope('qa.relaygroup')->draft);
        $this->assertNull($this->relay(0, 'password'), "neither A's secret nor the host's lands on B");
    }

    // ---- a kept child must still be admitted (Codex T19-R2b HIGH) ----

    public function test_a_child_whose_ceiling_narrowed_below_the_saving_tier_is_dropped_not_kept(): void
    {
        $full = [
            'deploy' => 'host-driver', 'host' => 'relay.op.test', 'token' => 'fake-op-token-1',
            'inner' => ['region' => 'us', 'apiKey' => 'fake-host-api', 'core' => ['zone' => 'z2', 'pin' => 'fake-host-pin']],
            'extra' => ['note' => 'op note', 'extraKey' => 'fake-op-extra-1'],
        ];
        $this->publish('qa.deep', [['pointer' => '/relay', 'op' => 'set', 'value' => $full]]);
        $this->assertSame('fake-op-token-1', config('qa.deep.relay.token'));

        class_exists(DeepRelayRedeclared::class);
        $this->registerSections(DeepRelayHostTokenSection::class);
        // A publish writes into THIS process's config at once; a later process boots from the host's values, so the tier
        // below is the host again (as in a real deploy of the narrowed declaration).
        config(['qa.deep.relay' => ['token' => 'fake-host-token'] + config('qa.deep.relay')]);
        $withoutToken = $full;
        unset($withoutToken['token']);

        // Inherited (the mirror import's form): the ceiling guard admits it; the old OPERATOR child is no longer admitted
        // by the host-only ceiling, so it is dropped and the host's value applies.
        $this->publish('qa.deep', [['pointer' => '/relay', 'op' => 'set', 'value' => $withoutToken, 'inherit' => ['/token']]]);
        $this->assertArrayNotHasKey('/relay/token', $this->store()->scope('qa.deep')->draft, 'the operator entry is gone');
        $this->assertSame('fake-host-token', config('qa.deep.relay.token'), 'the host value applies');

        // Left out: the leaf-ceiling guard refuses the whole save (a host-only leaf may not change), as before this ticket.
        // (An unset of the stale entry at its OWN pointer is always allowed: see the next test.)
        try {
            $this->store()->saveDraft('qa.deep', [['pointer' => '/relay', 'op' => 'set', 'value' => $withoutToken]]);
            $this->fail('the operator removed a host-only key');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('/relay/token', json_encode($refused->errors(), JSON_UNESCAPED_SLASHES));
        }
    }

    /** After a ceiling narrows, the tier can still UNSET its own stale entry (Claude r3 LOW): the host value then applies. */
    public function test_a_tier_can_unset_its_own_stale_secret_after_the_ceiling_narrowed(): void
    {
        $full = [
            'deploy' => 'host-driver', 'host' => 'relay.op.test', 'token' => 'fake-op-token-1',
            'inner' => ['region' => 'us', 'apiKey' => 'fake-host-api', 'core' => ['zone' => 'z2', 'pin' => 'fake-host-pin']],
            'extra' => ['note' => 'op note', 'extraKey' => 'fake-op-extra-1'],
        ];
        $this->publish('qa.deep', [['pointer' => '/relay', 'op' => 'set', 'value' => $full]]);
        class_exists(DeepRelayRedeclared::class);
        $this->registerSections(DeepRelayHostTokenSection::class);
        config(['qa.deep.relay' => ['token' => 'fake-host-token'] + config('qa.deep.relay')]);

        $this->publish('qa.deep', [['pointer' => '/relay/token', 'op' => 'unset']]);

        $this->assertArrayNotHasKey('/relay/token', $this->store()->scope('qa.deep')->draft, 'the stale operator entry is removed');
        $this->assertSame('fake-host-token', config('qa.deep.relay.token'));
    }

    // ---- the review's LOWs ----

    public function test_a_non_secret_null_ancestor_drops_the_secret_below_it(): void
    {
        $full = [
            'deploy' => 'host-driver', 'host' => 'relay.op.test', 'token' => 'fake-op-token-1',
            'inner' => ['region' => 'us', 'apiKey' => 'fake-host-api', 'core' => ['zone' => 'z2', 'pin' => 'fake-host-pin']],
            'extra' => ['note' => 'op note', 'extraKey' => 'fake-op-extra-1'],
        ];
        $this->publish('qa.deep', [['pointer' => '/relay', 'op' => 'set', 'value' => $full]]);

        $this->publish('qa.deep', [['pointer' => '/relay', 'op' => 'set', 'value' => ['extra' => null] + $full]]);

        $this->assertArrayNotHasKey('/relay/extra/extraKey', $this->store()->scope('qa.deep')->draft, 'a null object replaced the subtree');
        $this->assertNull(config('qa.deep.relay.extra'));
        $this->assertSame('fake-op-token-1', config('qa.deep.relay.token'), 'the token, carried again, is unaffected');
    }

    public function test_an_object_ancestor_sent_with_only_its_public_keys_keeps_the_secret(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/holder', 'op' => 'set', 'value' => ['primary' => ['host' => 'p.op.test', 'password' => 'fake-lg-a']]]]);

        $this->publish('qa.relaygroup', [['pointer' => '/holder', 'op' => 'set', 'value' => ['primary' => ['host' => 'p.op-2.test']]]]);

        $this->assertSame('fake-lg-a', config('qa.relaygroup.holder.primary.password'), 'kept below an object ancestor');
    }

    /** An EMPTY object ancestor (`{}`) counts as present (lead T19-R2-1435 LOW): the secret below it is kept. */
    public function test_an_empty_object_ancestor_counts_as_present(): void
    {
        $this->publish('qa.relaygroup', [['pointer' => '/boxes', 'op' => 'set', 'value' => ['box' => ['secret' => 'fake-lg-b']]]]);

        // `{}` as the HTTP layer hands it over (json_decode(..., true) gives []).
        $this->publish('qa.relaygroup', [['pointer' => '/boxes', 'op' => 'set', 'value' => ['box' => []]]]);

        $this->assertSame('fake-lg-b', config('qa.relaygroup.boxes.box.secret'), 'kept below an empty object');
    }
}
