<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\FoldReversedSection;
use Splicewire\Beam\Tests\Fixtures\Config\FoldSection;
use Splicewire\Beam\Tests\Fixtures\Config\MailersSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 20, review round 2: a declared secret below ANY nested Data shape is a secret, and a union of Data
 * classes that disagree keeps the strictest declaration.
 *
 * - A map of records (`#[MapValues(Entry::class)]`) and a list of records (`#[DataCollectionOf(Entry::class)]`) were never
 *   entered, so `/mailers/smtp/password` took the map's plain declaration: stored in plain, served by the reader. Entries
 *   are now walked FOR SECRECY ONLY: the map's own ceiling, merge and effect stand.
 * - With two Data members the ceiling, merge and effect came from whichever member was written first; now the strictest.
 *
 * Every value here is FAKE.
 */
class SecretBelowNestedShapesTest extends TestCase
{
    private const ROOT = 'qa.mailers';

    private const HOST_SECRETS = ['fake-map-host-pw', 'fake-map-host-auth', 'fake-list-host-pw', 'fake-group-host-pw'];

    private const OP_SECRETS = ['fake-map-op-pw', 'fake-map-op-auth', 'fake-map-op-pw2', 'fake-list-op-pw', 'fake-group-op-pw'];

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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-20b-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config([self::ROOT => [
            'mailers' => ['smtp' => ['driver' => 'smtp', 'host' => 'smtp.host.test', 'password' => 'fake-map-host-pw', 'auth' => ['label' => 'host auth', 'apiKey' => 'fake-map-host-auth']]],
            'relays' => [['driver' => 'relay', 'host' => 'relay.host.test', 'password' => 'fake-list-host-pw']],
            'group' => ['mailers' => ['g1' => ['driver' => 'group', 'host' => 'g1.host.test', 'password' => 'fake-group-host-pw']]],
        ]]);
        config(['qa.fold' => ['x' => ['k' => 'host k']], 'qa.fold-reversed' => ['x' => ['k' => 'host k']]]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(MailersSection::class, owner: 'qa/fixture');
        app(ConfigSectionRegistry::class)->section(FoldSection::class, owner: 'qa/fixture');
        app(ConfigSectionRegistry::class)->section(FoldReversedSection::class, owner: 'qa/fixture');
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

    private function wire(): string
    {
        return (string) json_encode(app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get(self::ROOT)));
    }

    private function assertNoOperatorSecretStored(): void
    {
        foreach (self::OP_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $this->stored(), "not in an override row, the scope draft or a version ({$secret})");
        }
    }

    // ---- what a key declares ----

    public function test_a_secret_in_a_map_entry_class_is_secret_with_an_entry_name_in_the_pointer(): void
    {
        $this->assertTrue(MailersSection::keyDeclaration(['mailers', 'smtp', 'password'])->secret);
        $this->assertFalse(MailersSection::keyDeclaration(['mailers', 'smtp', 'host'])->secret);
        $this->assertFalse(MailersSection::keyDeclaration(['mailers', 'smtp'])->secret, 'the entry itself is not a secret');
    }

    public function test_a_secret_in_a_list_entry_class_is_secret_with_an_index_in_the_pointer(): void
    {
        $this->assertTrue(MailersSection::keyDeclaration(['relays', '0', 'password'])->secret);
        $this->assertFalse(MailersSection::keyDeclaration(['relays', '0', 'host'])->secret);
    }

    public function test_a_map_nested_in_a_union_and_a_union_nested_in_a_map_are_walked(): void
    {
        $this->assertTrue(MailersSection::keyDeclaration(['group', 'mailers', 'g1', 'password'])->secret, 'a map below Nested|Optional');
        $this->assertTrue(MailersSection::keyDeclaration(['mailers', 'smtp', 'auth', 'apiKey'])->secret, 'Nested|Optional below a map entry');
    }

    /** Secrecy only: a map entry keeps the MAP's ceiling, merge and effect, not the bare #[ConfigKey] the generator puts on it. */
    public function test_a_map_entry_keeps_the_maps_ceiling_merge_and_effect(): void
    {
        foreach ([['mailers', 'smtp', 'driver'], ['mailers', 'smtp', 'password'], ['relays', '0', 'driver']] as $segments) {
            $declared = MailersSection::keyDeclaration($segments);
            $this->assertSame([ConfigTier::Operator, ConfigEffect::Live, ConfigMerge::Replace], [$declared->ceiling, $declared->effect, $declared->merge], implode('/', $segments));
        }
    }

    // ---- storage and the wire ----

    public function test_an_operator_secret_in_a_map_entry_is_never_stored_in_plain(): void
    {
        $this->store()->saveDraft(self::ROOT, [['pointer' => '/mailers/smtp', 'op' => 'set', 'value' => [
            'driver' => 'smtp', 'host' => 'smtp.op.test', 'password' => 'fake-map-op-pw', 'auth' => ['label' => 'op auth', 'apiKey' => 'fake-map-op-auth'],
        ]]]);
        $this->store()->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();

        $this->assertNoOperatorSecretStored();
        $this->assertSame(['fake-map-op-pw', 'fake-map-op-auth'], [config(self::ROOT.'.mailers.smtp.password'), config(self::ROOT.'.mailers.smtp.auth.apiKey')], 'the running configuration holds them, decrypted');

        $this->store()->saveDraft(self::ROOT, [['pointer' => '/mailers/smtp/password', 'op' => 'set', 'value' => 'fake-map-op-pw2']]);
        $this->assertNoOperatorSecretStored();
        $this->assertTrue($this->store()->scope(self::ROOT)->draft['/mailers/smtp/password']['secret'] ?? false, 'a direct write is encrypted');
    }

    /**
     * A write AT a secret in a list entry is refused (config-sections 21, option b); written with its whole list, the
     * secret is split out encrypted, never stored in plain.
     */
    public function test_an_operator_secret_in_a_list_entry_is_never_stored_in_plain(): void
    {
        try {
            $this->store()->saveDraft(self::ROOT, [['pointer' => '/relays/0/password', 'op' => 'set', 'value' => 'fake-list-op-pw']]);
            $this->fail('a set at a list item was accepted');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('/relays/0/password is inside a list item', json_encode($refused->errors(), JSON_UNESCAPED_SLASHES));
        }

        $relays = config(self::ROOT.'.relays');
        $relays[0]['password'] = 'fake-list-op-pw';
        $this->store()->saveDraft(self::ROOT, [['pointer' => '/relays', 'op' => 'set', 'value' => $relays]]);
        $this->store()->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();

        $this->assertNoOperatorSecretStored();
        $this->assertTrue($this->store()->scope(self::ROOT)->draft['/relays/0/password']['secret'] ?? false);
        $this->assertSame('fake-list-op-pw', config(self::ROOT.'.relays.0.password'));
    }

    public function test_an_operator_secret_in_a_map_below_a_union_is_never_stored_in_plain(): void
    {
        // Through a PARENT set of the union-typed property, then published (Codex r2 LOW: through to a snapshot).
        $this->store()->saveDraft(self::ROOT, [['pointer' => '/group', 'op' => 'set', 'value' => [
            'mailers' => ['g1' => ['driver' => 'group', 'host' => 'g1.op.test', 'password' => 'fake-group-op-pw']],
        ]]]);
        $this->store()->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();

        $this->assertNoOperatorSecretStored();
        $this->assertTrue($this->store()->scope(self::ROOT)->draft['/group/mailers/g1/password']['secret'] ?? false);
        $this->assertSame('fake-group-op-pw', config(self::ROOT.'.group.mailers.g1.password'));
    }

    public function test_the_reader_redacts_secrets_in_map_and_list_entries_on_the_wire(): void
    {
        foreach (self::HOST_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $this->wire(), "the host's secret never leaves through the reader ({$secret})");
        }

        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get(self::ROOT));
        $this->assertArrayHasKey('password', $row->values['mailers']['smtp'] ?? []);
        $this->assertNull($row->values['mailers']['smtp']['password']);
        $this->assertSame('smtp.host.test', $row->values['mailers']['smtp']['host'] ?? null, 'a plain key in the entry still reads');
    }

    // ---- two Data members that disagree (review MEDIUM) ----

    public function test_two_data_members_that_disagree_keep_the_strictest_declaration_in_either_order(): void
    {
        foreach ([FoldSection::class, FoldReversedSection::class] as $section) {
            $declared = $section::keyDeclaration(['x', 'k']);
            $this->assertSame(
                [ConfigTier::Host, ConfigEffect::Deploy, ConfigMerge::Extend, false],
                [$declared->ceiling, $declared->effect, $declared->merge, $declared->secret],
                "{$section}: the strictest ceiling, the heaviest effect, extend if any member extends",
            );
        }
    }

    public function test_the_store_refuses_a_write_the_strictest_member_does_not_admit_in_either_order(): void
    {
        foreach (['qa.fold', 'qa.fold-reversed'] as $root) {
            try {
                $this->store()->saveDraft($root, [['pointer' => '/x/k', 'op' => 'set', 'value' => 'operator k']]);
                $this->fail("{$root}: the operator tier wrote /x/k, which one member declares host-only");
            } catch (ValidationException $refused) {
                // The CEILING refusal, not the extend one (FoldTenantData also says extend; review r2 MEDIUM 1).
                $this->assertStringContainsString('/x/k has the ceiling host', json_encode($refused->errors(), JSON_UNESCAPED_SLASHES), $root);
            }
        }
    }
}
