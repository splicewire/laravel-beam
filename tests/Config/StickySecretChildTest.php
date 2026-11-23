<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\StickySecretSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 22 (NEW FINDING, NOT in the 20/19 land; a throwaway red off 84e7654, never committed to that stack).
 *
 * `/holder/creds` is declared SECRET as a whole object. Its child `token` carries its OWN `#[ConfigKey]` without
 * `secret: true`. keyDeclaration's nearest-declaration-wins rule (ConfigSection.php:220) lets that child declaration
 * REPLACE the ancestor's secret, so a set at the object's PARENT (`/holder`) leaves `token` in the owned value: plaintext
 * in the draft and in the revision value, and unredacted on read. These tests assert the CORRECT behaviour (secrecy is
 * sticky), so they are RED until ticket 22 lands. `bare` (no declaration of its own) is the green control.
 *
 * Every value here is FAKE.
 */
class StickySecretChildTest extends TestCase
{
    private const ROOT = 'qa.sticky';

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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-22-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config([self::ROOT => ['holder' => ['label' => 'host', 'creds' => ['token' => 'fake-sticky-host-token', 'bare' => 'fake-sticky-host-bare']]]]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(StickySecretSection::class, owner: 'qa/fixture');
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

    private function saveAtParent(): void
    {
        $this->store()->saveDraft(self::ROOT, [['pointer' => '/holder', 'op' => 'set', 'value' => [
            'label' => 'op', 'creds' => ['token' => 'fake-sticky-op-token', 'bare' => 'fake-sticky-op-bare'],
        ]]]);
    }

    private function draft(): string
    {
        return (string) json_encode($this->store()->scope(self::ROOT)->draft);
    }

    private function revisions(): string
    {
        return (string) json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
        ]);
    }

    private function wire(): string
    {
        return (string) json_encode(app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get(self::ROOT)));
    }

    public function test_a_child_with_its_own_config_key_under_a_secret_object_is_secret(): void
    {
        $this->assertTrue(StickySecretSection::keyDeclaration(['holder', 'creds'])->secret, 'the object is secret');
        $this->assertTrue(StickySecretSection::keyDeclaration(['holder', 'creds', 'bare'])->secret, 'control: no own declaration');
        $this->assertTrue(StickySecretSection::keyDeclaration(['holder', 'creds', 'token'])->secret, 'its own #[ConfigKey] must not lower the secret');
    }

    public function test_control_a_bare_child_set_at_the_parent_is_never_in_plain(): void
    {
        $this->saveAtParent();
        $this->assertStringNotContainsString('fake-sticky-op-bare', $this->draft(), 'not in the draft');
        $this->store()->publish(self::ROOT);
        $this->assertStringNotContainsString('fake-sticky-op-bare', $this->revisions(), 'not in a revision value or snapshot');
    }

    public function test_an_attributed_child_set_at_the_parent_is_not_plaintext_in_the_draft(): void
    {
        $this->saveAtParent();
        $this->assertStringNotContainsString('fake-sticky-op-token', $this->draft(), 'PLAINTEXT in the draft');
    }

    public function test_an_attributed_child_set_at_the_parent_is_not_plaintext_in_the_revision(): void
    {
        $this->saveAtParent();
        $this->store()->publish(self::ROOT);
        $this->assertStringNotContainsString('fake-sticky-op-token', $this->revisions(), 'PLAINTEXT in the revision value / snapshot');
    }

    public function test_control_the_reader_redacts_a_bare_child(): void
    {
        $this->assertStringNotContainsString('fake-sticky-host-bare', $this->wire());
    }

    public function test_the_reader_redacts_an_attributed_child(): void
    {
        $this->assertStringNotContainsString('fake-sticky-host-token', $this->wire(), 'UNREDACTED on read');
    }
}
