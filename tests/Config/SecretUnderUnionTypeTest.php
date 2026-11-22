<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\NullableSecretSection;
use Splicewire\Beam\Tests\Fixtures\Config\UnionEitherSection;
use Splicewire\Beam\Tests\Fixtures\Config\UnionSecretSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 20 (HIGH): a declared secret below a UNION-typed Data property is a secret.
 *
 * `ConfigSection::keyDeclaration()` followed nested Data only through a single named type, so a key below
 * `Nested|Optional` or `Nested|Optional|null` read as NOT secret: the store kept the operator's value in plain in the
 * override row, the scope draft and the version snapshot, and the reader put it on the wire. Every value here is FAKE.
 */
class SecretUnderUnionTypeTest extends TestCase
{
    private const ROOT = 'qa.union';

    private const OP_SECRETS = ['fake-union-op-opt', 'fake-union-op-optnull'];

    private const HOST_SECRETS = ['fake-union-host-opt', 'fake-union-host-optnull'];

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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-20-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config([self::ROOT => [
            'opt' => ['label' => 'host label', 'apiKey' => 'fake-union-host-opt'],
            'optNull' => ['label' => 'host label', 'apiKey' => 'fake-union-host-optnull'],
        ]]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(UnionSecretSection::class, owner: 'qa/fixture');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
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

    public function test_a_key_below_a_nested_or_optional_property_keeps_its_secret_declaration(): void
    {
        $this->assertTrue(UnionSecretSection::keyDeclaration(['opt', 'apiKey'])->secret, 'Nested|Optional');
        $this->assertTrue(UnionSecretSection::keyDeclaration(['optNull', 'apiKey'])->secret, 'Nested|Optional|null');
        $this->assertFalse(UnionSecretSection::keyDeclaration(['opt', 'label'])->secret, 'a plain key below stays plain');
    }

    /** Root's regression (AUDIT-0155): `?Nested` was always walked; it stays walked. */
    public function test_a_key_below_a_nullable_nested_property_keeps_its_secret_declaration(): void
    {
        $this->assertTrue(NullableSecretSection::keyDeclaration(['nullable', 'apiKey'])->secret);
        $this->assertFalse(NullableSecretSection::keyDeclaration(['nullable', 'label'])->secret);
    }

    public function test_a_union_of_two_data_classes_fails_towards_secret(): void
    {
        $this->assertTrue(UnionEitherSection::keyDeclaration(['either', 'apiKey'])->secret, 'secret in ANY member is secret');
        $this->assertFalse(UnionEitherSection::keyDeclaration(['either', 'label'])->secret, 'plain in every member stays plain');
        $this->assertFalse(UnionEitherSection::keyDeclaration(['either', 'plainOnly'])->secret, 'a key only one member declares keeps that declaration');
    }

    public function test_an_operator_secret_below_a_union_property_is_never_stored_in_plain(): void
    {
        $store = app(ConfigOverrideStore::class);

        $store->saveDraft(self::ROOT, [
            ['pointer' => '/opt', 'op' => 'set', 'value' => ['label' => 'op label', 'apiKey' => 'fake-union-op-opt']],
            ['pointer' => '/optNull', 'op' => 'set', 'value' => ['label' => 'op label', 'apiKey' => 'fake-union-op-optnull']],
        ]);
        $store->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();

        foreach (self::OP_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $this->stored(), "not in an override row, the scope draft or a version ({$secret})");
        }
        $this->assertSame(['fake-union-op-opt', 'fake-union-op-optnull'], [config(self::ROOT.'.opt.apiKey'), config(self::ROOT.'.optNull.apiKey')], 'the running configuration holds them, decrypted');
        $this->assertTrue($store->scope(self::ROOT)->draft['/opt/apiKey']['secret'] ?? false, 'split out as its own secret entry');
    }

    public function test_the_reader_redacts_a_secret_below_a_union_property_on_the_wire(): void
    {
        foreach (self::HOST_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $this->wire(), "the host's secret never leaves through the reader ({$secret})");
        }

        app(ConfigOverrideStore::class)->saveDraft(self::ROOT, [['pointer' => '/opt', 'op' => 'set', 'value' => ['label' => 'op label', 'apiKey' => 'fake-union-op-opt']]]);
        app(ConfigOverrideStore::class)->publish(self::ROOT);
        app(ConfigOverlay::class)->sync();

        $this->assertStringNotContainsString('fake-union-op-opt', $this->wire(), "nor the operator's");
        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get(self::ROOT));
        $this->assertArrayHasKey('apiKey', $row->values['opt'] ?? [], 'the key is listed');
        $this->assertNull($row->values['opt']['apiKey'], 'its value is redacted to null');
        $this->assertSame('op label', $row->values['opt']['label'] ?? null);
    }
}
