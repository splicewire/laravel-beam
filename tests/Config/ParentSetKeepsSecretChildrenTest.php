<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelayNoTokenSection;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelayPlainTokenSection;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelayRedeclared;
use Splicewire\Beam\Tests\Fixtures\Config\DeepRelaySection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 19: a parent `set` keeps the secret children it inherits.
 *
 * A declared secret inside an object `set` is split out into its own entry (`via` = the parent's pointer) and listed in
 * the parent's `inherit`. A later parent `set` that LEAVES THE SECRET OUT (the only thing a caller that cannot read it
 * can send) now keeps the stored secret, instead of dropping it. The rule, at every depth below the op's pointer P:
 * - a child whose key the incoming value CARRIES is replaced (as before);
 * - a child the value leaves out is KEPT when every ancestor of it below P is present in the value as an object, or the
 *   op's own `inherit` names it or one of its ancestors; it joins P's `inherit` and its `via` moves to P;
 * - otherwise it is dropped: P is `null` or `unset`, an ancestor of it is absent (the operator removed that object) or
 *   not an object, P itself is declared secret, or the child is no longer declared secret (or no longer declared).
 * Removing a secret is explicit: `unset` or `null` on its own pointer, or `unset` of the parent.
 *
 * Every secret here is FAKE. Same database wiring as {@see ConfigOverridesTest}.
 */
class ParentSetKeepsSecretChildrenTest extends TestCase
{
    private const ROOT = 'qa.deep';

    /** The fake operator secrets: none may ever reach a stored row, a scope, a version or a wire. */
    private const SECRETS = ['fake-op-token-1', 'fake-op-token-2', 'fake-op-api-1', 'fake-op-pin-1', 'fake-op-extra-1', 'fake-op-inner-api', 'fake-op-inner-pin'];

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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-19-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config([self::ROOT => ['relay' => self::host()]]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->declare(DeepRelaySection::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    /** @return array<string, mixed> the host's `qa.deep.relay` object (the tier below the operator) */
    private static function host(): array
    {
        return [
            'deploy' => 'host-driver', 'host' => 'relay.host.test', 'token' => 'fake-host-token',
            'inner' => ['region' => 'eu', 'apiKey' => 'fake-host-api', 'core' => ['zone' => 'z1', 'pin' => 'fake-host-pin']],
            'extra' => ['note' => 'host note', 'extraKey' => 'fake-host-extra'],
        ];
    }

    /** The operator's first, complete write: every secret set, at every level. @return array<string, mixed> */
    private static function full(): array
    {
        return [
            'deploy' => 'host-driver', 'host' => 'relay.op.test', 'token' => 'fake-op-token-1',
            'inner' => ['region' => 'us', 'apiKey' => 'fake-op-api-1', 'core' => ['zone' => 'z2', 'pin' => 'fake-op-pin-1']],
            'extra' => ['note' => 'op note', 'extraKey' => 'fake-op-extra-1'],
        ];
    }

    private function declare(string $section): void
    {
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section($section, owner: 'qa/fixture');
    }

    private function store(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    /** @param  list<array<string, mixed>>  $ops */
    private function save(array $ops): void
    {
        $this->store()->saveDraft(self::ROOT, $ops);
        $this->assertNoSecretStored();
    }

    /** @param  list<array<string, mixed>>  $ops */
    private function publish(array $ops): void
    {
        $this->save($ops);
        $this->store()->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();
        $this->assertNoSecretStored();
    }

    private function setRelay(array $value, array $extra = []): array
    {
        return ['pointer' => '/relay', 'op' => 'set', 'value' => $value] + $extra;
    }

    /** @return array<string, array<string, mixed>> */
    private function draft(): array
    {
        return $this->store()->scope(self::ROOT)->draft ?? [];
    }

    private function relay(string $key): mixed
    {
        return config(self::ROOT.'.relay.'.$key);
    }

    private function assertNoSecretStored(): void
    {
        $raw = json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('config_scopes'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
        ]);

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, (string) $raw, "no secret value in a stored row, a scope or a version ({$secret})");
        }
    }

    /** The full write again, with the given leaves (relative to /relay) left OUT and nothing else changed but `host`. */
    private function without(string ...$leaves): array
    {
        $value = self::full();
        $value['host'] = 'relay.op-2.test';

        foreach ($leaves as $leaf) {
            $segments = explode('/', ltrim($leaf, '/'));
            $last = array_pop($segments);
            $cursor = &$value;
            foreach ($segments as $segment) {
                $cursor = &$cursor[$segment];
            }
            unset($cursor[$last]);
            unset($cursor);
        }

        return $value;
    }

    // ---- the ticket's boxes ----

    public function test_a_parent_set_with_only_public_keys_changed_keeps_its_secret_child(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/token'))]);

        $this->assertSame('relay.op-2.test', $this->relay('host'), 'the public change applies');
        $this->assertSame('fake-op-token-1', $this->relay('token'), 'the secret the resave left out is still stored and still applies');
        $this->assertSame('/relay', $this->draft()['/relay/token']['via'] ?? null);
        $this->assertContains('/token', $this->draft()['/relay']['inherit'] ?? []);
    }

    /**
     * The Config area's round trip: the reader serves a secret leaf inside an object as `null`, and the edit slot sends the
     * object back as it read it. That `null` is the redaction placeholder, not an explicit null: the stored secret stays.
     */
    public function test_a_resave_carrying_the_redaction_null_at_a_secret_leaf_keeps_the_secret(): void
    {
        $this->publish([$this->setRelay(self::full())]);
        $read = $this->without();
        $read['token'] = null;
        $read['inner']['core']['pin'] = null;

        $this->publish([$this->setRelay($read)]);

        $this->assertSame(['fake-op-token-1', 'fake-op-pin-1'], [$this->relay('token'), $this->relay('inner.core.pin')]);
        $this->assertSame('set', $this->draft()['/relay/token']['op'] ?? null, 'never turned into an explicit-null override');
    }

    public function test_a_redaction_null_with_no_stored_secret_inherits_the_tier_below(): void
    {
        $value = self::full();
        $value['token'] = null;

        $this->publish([$this->setRelay($value)]);

        $this->assertArrayNotHasKey('/relay/token', $this->draft());
        $this->assertContains('/token', $this->draft()['/relay']['inherit'] ?? []);
        $this->assertSame('fake-host-token', $this->relay('token'));
    }

    public function test_a_secret_two_levels_down_is_kept(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/inner/apiKey'))]);

        $this->assertSame('fake-op-api-1', $this->relay('inner.apiKey'));
        $this->assertSame('us', $this->relay('inner.region'));
    }

    public function test_a_secret_three_levels_down_is_kept(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/inner/core/pin'))]);

        $this->assertSame('fake-op-pin-1', $this->relay('inner.core.pin'));
        $this->assertSame('z2', $this->relay('inner.core.zone'));
    }

    public function test_two_secrets_under_one_parent_are_both_kept(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/token', '/inner/apiKey'))]);

        $this->assertSame(['fake-op-token-1', 'fake-op-api-1'], [$this->relay('token'), $this->relay('inner.apiKey')]);
    }

    public function test_a_parent_set_that_carries_a_new_secret_value_replaces_it(): void
    {
        $this->publish([$this->setRelay(self::full())]);
        $value = self::full();
        $value['token'] = 'fake-op-token-2';

        $this->publish([$this->setRelay($value)]);

        $this->assertSame('fake-op-token-2', $this->relay('token'));
    }

    public function test_an_unset_on_the_child_removes_it_and_the_key_falls_back_to_the_tier_below(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([['pointer' => '/relay/token', 'op' => 'unset']]);

        $this->assertSame('fake-host-token', $this->relay('token'));
        $this->assertArrayNotHasKey('/relay/token', $this->draft());
        $this->assertSame('relay.op.test', $this->relay('host'), 'the parent stays');
    }

    public function test_an_explicit_null_on_the_child_removes_its_value(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([['pointer' => '/relay/token', 'op' => 'null']]);

        $this->assertNull($this->relay('token'));
        $this->assertSame('null', $this->draft()['/relay/token']['op'] ?? null);
    }

    public function test_an_unset_of_the_parent_removes_the_parent_and_its_children(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([['pointer' => '/relay', 'op' => 'unset']]);

        $this->assertSame([], array_filter(array_keys($this->draft()), fn (string $p): bool => str_starts_with($p, '/relay')));
        $this->assertSame(['fake-host-token', 'fake-host-api', 'relay.host.test'], [$this->relay('token'), $this->relay('inner.apiKey'), $this->relay('host')]);
    }

    /** The rule stated: a parent `null` replaces the whole object, so the secrets split out of it go with it. */
    public function test_a_parent_null_drops_the_secret_children_split_out_of_it(): void
    {
        $this->publish([['pointer' => '/relay/extra', 'op' => 'set', 'value' => ['note' => 'op note', 'extraKey' => 'fake-op-extra-1']]]);
        $this->assertArrayHasKey('/relay/extra/extraKey', $this->draft());

        $this->publish([['pointer' => '/relay/extra', 'op' => 'null']]);

        $this->assertArrayNotHasKey('/relay/extra/extraKey', $this->draft(), 'a null parent keeps no child: it would rebuild a key under null');
        $this->assertNull($this->relay('extra'));
    }

    /** The rule stated: a kept child must still be declared secret at the moment of the parent op; otherwise it is dropped. */
    public function test_a_child_no_longer_declared_secret_does_not_linger(): void
    {
        $this->publish([$this->setRelay(self::full())]);
        class_exists(DeepRelayRedeclared::class);
        $this->declare(DeepRelayPlainTokenSection::class);

        $this->publish([$this->setRelay($this->without('/token'))]);

        $this->assertArrayNotHasKey('/relay/token', $this->draft(), 'not kept: folding it into the parent would write a formerly-secret value in plain');
        $this->assertNotSame('fake-op-token-1', $this->relay('token'));
    }

    public function test_a_child_no_longer_declared_does_not_linger(): void
    {
        $this->publish([$this->setRelay(self::full())]);
        class_exists(DeepRelayRedeclared::class);
        $this->declare(DeepRelayNoTokenSection::class);

        $this->publish([$this->setRelay($this->without('/token'))]);

        $this->assertArrayNotHasKey('/relay/token', $this->draft());
        $this->assertNotSame('fake-op-token-1', $this->relay('token'));
    }

    public function test_host_ceiling_keys_under_the_parent_behave_as_before(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/token'))]);

        $inherit = $this->draft()['/relay']['inherit'] ?? [];
        $this->assertSame('/deploy', $inherit[0] ?? null, 'the host-only key is still inherited, first, as before');
        $this->assertArrayNotHasKey('deploy', $this->draft()['/relay']['value'] ?? [], 'and never pinned in the stored object');
        $this->assertSame('host-driver', $this->relay('deploy'));
    }

    public function test_publish_rollback_and_the_version_history_show_the_kept_child_and_no_secret_leaks(): void
    {
        $this->publish([$this->setRelay(self::full())]);
        $first = $this->store()->versionsOf(self::ROOT);

        $this->publish([$this->setRelay($this->without('/token', '/inner/core/pin'))]);
        $this->assertSame(['fake-op-token-1', 'fake-op-pin-1'], [$this->relay('token'), $this->relay('inner.core.pin')], 'published: the kept children apply');
        $layer = $this->store()->publishedLayer($this->store()->scope(self::ROOT));
        $this->assertTrue($layer['/relay/token']->secret, 'the published layer carries the kept child, still secret');
        $this->assertGreaterThan(count($first), count($this->store()->versionsOf(self::ROOT)), 'the history records the resave');

        $this->store()->restore(self::ROOT, end($first)->id);
        $this->store()->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();

        $this->assertSame(['relay.op.test', 'fake-op-token-1'], [$this->relay('host'), $this->relay('token')], 'a rollback to the first version restores it, secret included');
        $this->assertNoSecretStored();
        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get(self::ROOT));
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, (string) json_encode($row), 'the reader (the wire) redacts it');
        }
    }

    // ---- the lead's refinement (RULE-0110) ----

    public function test_a_child_under_an_ancestor_the_resave_leaves_out_is_dropped_not_resurrected(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/extra'))]);

        $this->assertArrayNotHasKey('/relay/extra/extraKey', $this->draft(), 'the operator removed extra; its secret goes with it');
        $this->assertNull($this->relay('extra'), 'no extra holding only a secret');
    }

    public function test_the_same_resave_whose_inherit_names_the_ancestor_keeps_the_child(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/extra'), ['inherit' => ['/extra']])]);

        $this->assertSame('/relay', $this->draft()['/relay/extra/extraKey']['via'] ?? null);
        $this->assertSame('fake-op-extra-1', $this->relay('extra.extraKey'));
    }

    public function test_a_kept_child_applies_inside_the_parent_at_every_level_and_a_later_unset_falls_back(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/token', '/inner/apiKey', '/inner/core/pin', '/extra/extraKey'))]);

        $this->assertSame(
            ['fake-op-token-1', 'fake-op-api-1', 'fake-op-pin-1', 'fake-op-extra-1'],
            [$this->relay('token'), $this->relay('inner.apiKey'), $this->relay('inner.core.pin'), $this->relay('extra.extraKey')],
            'the overlay applies every kept child inside the parent value',
        );

        $this->publish([['pointer' => '/relay/inner/core/pin', 'op' => 'unset']]);

        $this->assertSame('fake-host-pin', $this->relay('inner.core.pin'), 'a later unset of a kept child falls back to the tier below');
        $this->assertSame('fake-op-api-1', $this->relay('inner.apiKey'), 'the others stay');
    }

    // ---- the rule's edges ----

    public function test_a_set_of_a_higher_parent_keeps_a_secret_split_by_a_deeper_set_and_re_points_it(): void
    {
        $this->publish([['pointer' => '/relay/inner', 'op' => 'set', 'value' => ['region' => 'ap', 'apiKey' => 'fake-op-inner-api', 'core' => ['zone' => 'z3', 'pin' => 'fake-op-inner-pin']]]]);
        $this->assertSame('/relay/inner', $this->draft()['/relay/inner/apiKey']['via'] ?? null);

        $this->publish([$this->setRelay($this->without('/inner/apiKey', '/inner/core/pin'))]);

        $this->assertSame('/relay', $this->draft()['/relay/inner/apiKey']['via'] ?? null, 'the newest owner op now holds it');
        $this->assertContains('/inner/apiKey', $this->draft()['/relay']['inherit'] ?? []);
        $this->assertSame('fake-op-inner-api', $this->relay('inner.apiKey'));
    }

    /** A mirror import names the leaves a set does not own in `inherit`: the target's own secret stays, the host's is never pinned. */
    public function test_an_op_inherit_naming_a_secret_keeps_the_targets_secret_and_never_pins_the_lower_tiers(): void
    {
        $this->publish([$this->setRelay(self::full())]);

        $this->publish([$this->setRelay($this->without('/token'), ['inherit' => ['/token']])]);

        $this->assertSame('fake-op-token-1', $this->relay('token'), 'the target keeps its own secret');
        $this->assertSame('/relay', $this->draft()['/relay/token']['via'] ?? null);

        $this->publish([['pointer' => '/relay/token', 'op' => 'unset']]);
        $this->publish([$this->setRelay($this->without('/token'), ['inherit' => ['/token']])]);

        $this->assertArrayNotHasKey('/relay/token', $this->draft(), 'with no stored secret, an inherited leaf is not turned into an override of the tier below');
        $this->assertSame('fake-host-token', $this->relay('token'));
    }
}
