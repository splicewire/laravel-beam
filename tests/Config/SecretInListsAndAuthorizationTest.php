<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\CeilingShiftSection;
use Splicewire\Beam\Tests\Fixtures\Config\EnvConflictSection;
use Splicewire\Beam\Tests\Fixtures\Config\RelayGroupSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 20, review round 3:
 * - (r2 HIGH) a declared secret in a LIST of records BELOW the pointer an operator writes is split by index, so it never
 *   reaches the override row, the scope draft or a version snapshot in plain;
 * - (r2 MEDIUM 2, disclosed) the full descent into unions and intersections changes AUTHORIZATION below them: the inner
 *   class's own ceiling now decides, one test each way;
 * - (Codex r2 LOW) the env fold keeps null and '' apart.
 *
 * Every value here is FAKE.
 */
class SecretInListsAndAuthorizationTest extends TestCase
{
    private const OP_SECRETS = ['fake-lg-group', 'fake-lg-map', 'fake-lg-union', 'fake-lg-group-2', 'fake-lg-holder'];

    private const HOST_SECRETS = ['fake-lg-host-group', 'fake-lg-host-map', 'fake-lg-host-union', 'fake-lg-host-holder'];

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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-20d-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        $relay = fn (string $password): array => ['relays' => [['host' => 'relay.host.test', 'password' => $password]]];
        config([
            'qa.relaygroup' => ['group' => $relay('fake-lg-host-group'), 'byName' => ['a' => $relay('fake-lg-host-map')], 'maybe' => $relay('fake-lg-host-union'), 'holder' => ['primary' => ['host' => 'primary.host.test', 'password' => 'fake-lg-host-holder']]],
            'qa.shift' => ['viaUnion' => ['host' => 'h', 'open' => 'o'], 'viaIntersection' => ['host' => 'h', 'open' => 'o']],
        ]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        foreach ([RelayGroupSection::class, CeilingShiftSection::class] as $section) {
            app(ConfigSectionRegistry::class)->section($section, owner: 'qa/fixture');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    private function store(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    private function stored(): string
    {
        return (string) json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('config_scopes'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
        ]);
    }

    private function assertNoOperatorSecretStored(): void
    {
        foreach (self::OP_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $this->stored(), "not in an override row, the scope draft or a version ({$secret})");
        }
    }

    /** @return array<string, mixed> */
    private static function relays(string ...$passwords): array
    {
        return ['relays' => array_map(fn (string $p): array => ['host' => 'relay.op.test', 'password' => $p], $passwords)];
    }

    private function publishSet(string $pointer, array $value): void
    {
        $this->store()->saveDraft('qa.relaygroup', [['pointer' => $pointer, 'op' => 'set', 'value' => $value]]);
        $this->store()->publish('qa.relaygroup');
        app(ConfigOverlay::class)->sync();
    }

    // ---- a list of records below the pointer written (r2 HIGH) ----

    public function test_a_parent_set_splits_a_secret_in_a_list_inside_a_nested_data_class(): void
    {
        $this->publishSet('/group', self::relays('fake-lg-group', 'fake-lg-group-2'));

        $this->assertNoOperatorSecretStored();
        $draft = $this->store()->scope('qa.relaygroup')->draft;
        $this->assertSame([true, true], [$draft['/group/relays/0/password']['secret'] ?? false, $draft['/group/relays/1/password']['secret'] ?? false], 'one encrypted entry per item, by index');
        $this->assertSame(['fake-lg-group', 'fake-lg-group-2'], [config('qa.relaygroup.group.relays.0.password'), config('qa.relaygroup.group.relays.1.password')], 'the running configuration holds them, decrypted');
    }

    public function test_a_parent_set_splits_a_secret_in_a_list_inside_a_map_entry(): void
    {
        $this->publishSet('/byName/a', self::relays('fake-lg-map'));

        $this->assertNoOperatorSecretStored();
        $this->assertTrue($this->store()->scope('qa.relaygroup')->draft['/byName/a/relays/0/password']['secret'] ?? false);
        $this->assertSame('fake-lg-map', config('qa.relaygroup.byName.a.relays.0.password'));
    }

    public function test_a_parent_set_splits_a_secret_in_a_list_inside_a_union_member(): void
    {
        $this->publishSet('/maybe', self::relays('fake-lg-union'));

        $this->assertNoOperatorSecretStored();
        $this->assertTrue($this->store()->scope('qa.relaygroup')->draft['/maybe/relays/0/password']['secret'] ?? false);
        $this->assertSame('fake-lg-union', config('qa.relaygroup.maybe.relays.0.password'));
    }

    /**
     * A list index is not a stable identity (lead T19-R2-1435): a secret split out of a list item must NOT outlive a later
     * set of the list (or of an ancestor), or it would re-apply at that index to whatever record now sits there.
     * Green at f7ffaf5 (nothing was split, the plain secret went with its parent value); it guards r3's split.
     */
    public function test_a_later_set_of_the_list_drops_the_index_children_so_no_secret_moves_to_another_record(): void
    {
        $this->publishSet('/group', self::relays('fake-lg-group', 'fake-lg-group-2'));

        $this->publishSet('/group/relays', [['host' => 'relay-b.op.test']]);

        $draft = $this->store()->scope('qa.relaygroup')->draft;
        $this->assertArrayNotHasKey('/group/relays/0/password', $draft, "relay A's split secret did not survive the list's replacement");
        $this->assertSame('relay-b.op.test', config('qa.relaygroup.group.relays.0.host'));
        $this->assertNull(config('qa.relaygroup.group.relays.0.password'), "relay B has no secret: neither relay A's nor the host's (review r3 LOW)");
    }

    /**
     * BEHAVIOUR CHANGE pinned (lead OK-1445): a parent op decides every split child below it by pointer prefix, maps and
     * objects too. Ticket 20 r3 alone DROPPED the secret a deeper set of /holder/primary split out on a set of /holder.
     * Config-sections 19 gives it back: kept and re-pointed to /holder, because the re-save leaves the leaf out and its
     * object ancestor is present. (Under 20 alone this pinned the drop; 19 changes it on purpose. 20 and 19 land together.)
     */
    public function test_a_parent_set_keeps_and_re_points_a_secret_a_deeper_set_split_out(): void
    {
        $this->publishSet('/holder/primary', ['host' => 'primary.op.test', 'password' => 'fake-lg-holder']);
        $this->assertSame('/holder/primary', $this->store()->scope('qa.relaygroup')->draft['/holder/primary/password']['via'] ?? null);

        $this->publishSet('/holder', ['primary' => ['host' => 'primary.op-2.test']]);

        $this->assertSame('/holder', $this->store()->scope('qa.relaygroup')->draft['/holder/primary/password']['via'] ?? null, 'kept, and re-pointed to the ancestor (config-sections 19)');
        // The EFFECT: the deeper /holder/primary entry stays in force, and with 19 it keeps its secret.
        $this->assertSame('primary.op.test', config('qa.relaygroup.holder.primary.host'), 'the deeper entry still applies over the ancestor');
        $this->assertSame('fake-lg-holder', config('qa.relaygroup.holder.primary.password'));
        $this->assertNoOperatorSecretStored();
    }

    public function test_the_reader_redacts_a_secret_in_a_list_below_any_parent(): void
    {
        $wire = (string) json_encode(app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get('qa.relaygroup')));

        foreach (self::HOST_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $wire, "the reader redacts {$secret}");
        }
    }

    // ---- the authorization change below unions and intersections (r2 MEDIUM 2, disclosed) ----

    /** An operator-raised outer ceiling over an inner bare #[ConfigKey] (host) is now REFUSED (it was admitted at c96c93dd). */
    public function test_an_inner_bare_key_below_a_union_or_intersection_now_refuses_the_operator(): void
    {
        foreach (['/viaUnion/host', '/viaIntersection/host'] as $pointer) {
            $this->assertSame(ConfigTier::Host, CeilingShiftSection::keyDeclaration(explode('/', ltrim($pointer, '/')))->ceiling, $pointer);

            try {
                $this->store()->saveDraft('qa.shift', [['pointer' => $pointer, 'op' => 'set', 'value' => 'operator value']]);
                $this->fail("{$pointer}: the operator wrote a key its inner class declares host-only");
            } catch (ValidationException $refused) {
                $this->assertStringContainsString("{$pointer} has the ceiling host", json_encode($refused->errors(), JSON_UNESCAPED_SLASHES));
            }
        }
    }

    /** An inner ceiling ABOVE the outer one (tenant under operator) is now ADMITTED for the tenant tier (refused at c96c93dd). */
    public function test_an_inner_ceiling_above_the_outer_one_now_admits_the_tenant_tier(): void
    {
        foreach (['/viaUnion/open', '/viaIntersection/open'] as $pointer) {
            $declared = CeilingShiftSection::keyDeclaration(explode('/', ltrim($pointer, '/')));
            $this->assertSame(ConfigTier::Tenant, $declared->ceiling, $pointer);
            $this->assertTrue($declared->ceiling->admits(ConfigTier::Tenant), "{$pointer}: the tenant tier may write it");
        }

        $this->store()->saveDraft('qa.shift', [['pointer' => '/viaUnion/open', 'op' => 'set', 'value' => 'operator value']]);
        $this->assertArrayHasKey('/viaUnion/open', $this->store()->scope('qa.shift')->draft, 'and the operator, below it, still may');
    }

    // ---- env: null and '' are different (Codex r2 LOW) ----

    public function test_union_members_naming_no_env_and_the_empty_env_disagree_in_either_order(): void
    {
        $this->assertNull(EnvConflictSection::keyDeclaration(['nullFirst', 'k'])->env);
        $this->assertNull(EnvConflictSection::keyDeclaration(['emptyFirst', 'k'])->env);
    }
}
