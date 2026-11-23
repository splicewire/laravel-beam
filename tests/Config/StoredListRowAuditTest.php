<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\ConfigStoredListRowAudit;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\LegacyRelayGroupSection;
use Splicewire\Beam\Tests\Fixtures\Config\RelayGroupSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 21 (lead LANE-A-0420): a published row written through a list index stops applying once the key is
 * declared a list. The doctor names those rows per section and tier, and changes nothing. Every value here is FAKE.
 */
class StoredListRowAuditTest extends TestCase
{
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
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-21d-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->declare(RelayGroupSection::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    /** @return array<string, mixed> */
    private static function host(): array
    {
        return [
            'group' => ['relays' => [['host' => 'relay.host.test', 'password' => 'fake-sl-host-0']]],
            'byName' => ['a' => ['relays' => [['host' => 'a.host.test', 'password' => 'fake-sl-host-a']]]],
            'holder' => ['primary' => ['host' => 'p.host.test', 'password' => 'fake-sl-host-p']],
        ];
    }

    private function declare(string $section): void
    {
        config(['qa.relaygroup' => self::host()]);
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section($section, owner: 'qa/fixture');
        app(ConfigOverlay::class)->sync();
    }

    /** @return list<\Rushing\Doctor\Finding> */
    private function audit(): array
    {
        return app(ConfigStoredListRowAudit::class)->run();
    }

    /** @return array<string, int> table => row count, to show the audit writes nothing */
    private function counts(): array
    {
        $counts = [];

        foreach (['config_scopes', 'config_overrides', 'versions'] as $table) {
            $counts[$table] = DB::connection('central')->table(Beam::table($table))->count();
        }

        return $counts;
    }

    public function test_a_published_row_through_a_list_index_is_named_per_section_and_tier_and_nothing_changes(): void
    {
        $this->declare(LegacyRelayGroupSection::class);
        app(ConfigOverrideStore::class)->saveDraft('qa.relaygroup', [
            ['pointer' => '/group/relays/0', 'op' => 'set', 'value' => ['host' => 'relay.legacy.test', 'password' => 'fake-sl-legacy']],
            ['pointer' => '/group/relays/7/host', 'op' => 'set', 'value' => 'relay.seven.test'],
            ['pointer' => '/group/tags', 'op' => 'set', 'value' => ['fake-sl-tag']],
        ]);
        app(ConfigOverrideStore::class)->publish('qa.relaygroup');
        $this->declare(RelayGroupSection::class);
        $before = $this->counts();

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertSame(ConfigStoredListRowAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('qa.relaygroup (operator tier): ', $findings[0]->detail);
        $this->assertStringContainsString('no longer apply', $findings[0]->detail);
        $this->assertStringContainsString('tenants:run splicewire:beam:doctor', $findings[0]->detail, 'a central warning names the per-tenant run');
        // The item set at /group/relays/0 split its password out under `via`: one op, so the parent and its split secret
        // both drop. The whole-key row /group/tags still applies and is not named.
        $this->assertStringContainsString(': 3 published override row(s)', $findings[0]->detail, $findings[0]->detail);
        $this->assertStringContainsString('/group/relays/0', $findings[0]->detail);
        $this->assertStringContainsString('/group/relays/7/host', $findings[0]->detail);
        $this->assertStringNotContainsString('/group/tags', $findings[0]->detail);
        $this->assertStringNotContainsString('fake-sl-', $findings[0]->detail, 'the finding names pointers, never values');
        $this->assertSame(['fake-sl-tag'], config('qa.relaygroup.group.tags'), 'the whole-key row still applies');
        $this->assertSame($before, $this->counts(), 'read-only: no row is written, repaired or removed');
    }

    public function test_a_list_written_whole_is_not_a_stored_list_row(): void
    {
        app(ConfigOverrideStore::class)->saveDraft('qa.relaygroup', [
            ['pointer' => '/group/relays', 'op' => 'set', 'value' => [['host' => 'relay.op.test', 'password' => 'fake-sl-op']]],
        ]);
        app(ConfigOverrideStore::class)->publish('qa.relaygroup');
        $this->declare(RelayGroupSection::class);

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status, $findings[0]->detail);
        $this->assertStringContainsString('tiers checked: operator', $findings[0]->detail);
        $this->assertStringEndsWith(ConfigStoredListRowAudit::TENANTS_UNCHECKED, $findings[0]->detail, 'a central pass says the tenants were not checked');
        $this->assertSame('fake-sl-op', config('qa.relaygroup.group.relays.0.password'), 'its split secret applies');
    }

    public function test_it_runs_under_the_doctor_and_stays_advisory(): void
    {
        $registrations = array_column(array_map(fn ($registration) => [$registration->audit, $registration], app(BeamDoctorManifest::class)->registrations()), 1, 0);

        $this->assertArrayHasKey(ConfigStoredListRowAudit::class, $registrations);
        $this->assertFalse($registrations[ConfigStoredListRowAudit::class]->gate);
    }
}
