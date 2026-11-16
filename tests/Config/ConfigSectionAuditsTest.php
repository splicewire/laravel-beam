<?php

namespace Splicewire\Beam\Tests\Config;

use Rushing\Doctor\Finding;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\BootReadConfigAudit;
use Splicewire\Beam\Doctor\ConfigSectionCoverageAudit;
use Splicewire\Beam\Testing\BootReadProbe;
use Splicewire\Beam\Tests\Fixtures\Config\BootReadFixtureProvider;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * The two config audits and the boot-read probe (config-sections ticket 06), on the `widgets` fixture: `enabled` is
 * declared live, and {@see BootReadFixtureProvider} reads it during boot.
 */
class ConfigSectionAuditsTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    private BootReadProbe $probe;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), BootReadFixtureProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('widgets', array_merge(require self::FIXTURES.'/package/widgets.php', require self::FIXTURES.'/host/widgets.php'));
    }

    /** The probe goes in as soon as the configuration loads, before any provider registers. */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $this->probe = BootReadProbe::install($app);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe->stop();

        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        app(ConfigSectionRegistry::class)->section(WidgetsSection::class, owner: 'acme/widgets', packageFile: realpath(self::FIXTURES.'/package/widgets.php'));
    }

    /** @param list<Finding> $findings */
    private function messages(array $findings): string
    {
        return implode("\n", array_map(fn (Finding $finding): string => $finding->detail, $findings));
    }

    public function test_coverage_reports_a_merged_key_with_no_section(): void
    {
        config()->set('widgets.colour', 'blue');
        config()->set('widgets.limits.burst', 5);

        $messages = $this->messages(app(ConfigSectionCoverageAudit::class)->run());

        $this->assertStringContainsString('widgets: 2 key(s) in config() are covered by no section key', $messages);
        $this->assertStringContainsString('/colour', $messages);
        $this->assertStringContainsString('/limits/burst', $messages);
    }

    public function test_a_section_whose_package_default_fails_validation_is_a_finding(): void
    {
        app(ConfigSectionRegistry::class)->section(WidgetsSection::class, owner: 'acme/widgets', packageFile: realpath(self::FIXTURES.'/package/widgets-misdescribed.php'));

        $findings = app(ConfigSectionCoverageAudit::class)->run();
        $failed = array_filter($findings, fn (Finding $finding): bool => str_contains($finding->detail, 'do not validate'));

        $this->assertCount(1, $failed);
        $this->assertStringContainsString('widgets-misdescribed.php', $this->messages($failed));

        // …and the real package file validates.
        app(ConfigSectionRegistry::class)->section(WidgetsSection::class, owner: 'acme/widgets', packageFile: realpath(self::FIXTURES.'/package/widgets.php'));
        $this->assertStringNotContainsString('do not validate', $this->messages(app(ConfigSectionCoverageAudit::class)->run()));
    }

    public function test_coverage_reports_a_missing_description(): void
    {
        // WidgetsSection's `limits`, `driver` and the limits' keys carry no #[Description].
        $messages = $this->messages(app(ConfigSectionCoverageAudit::class)->run());

        $this->assertStringContainsString('no description, or still the harvested draft', $messages);
        $this->assertStringContainsString('/driver', $messages);
    }

    public function test_the_probe_flags_a_live_key_a_provider_reads_during_boot(): void
    {
        $violations = $this->probe->violations(app(ConfigSectionRegistry::class));

        $this->assertCount(1, $violations, implode("\n", $violations));
        $this->assertStringContainsString('widgets.enabled is declared live but was read during boot', $violations[0]);
        $this->assertStringContainsString(BootReadFixtureProvider::class.'::boot', $violations[0]);

        // A deploy key read at boot is fine, and the mechanism's own reads (mergeConfigFrom, the overlay) are not recorded.
        $this->assertArrayHasKey('widgets.driver', $this->probe->reads());
        $this->assertArrayNotHasKey('beam.core.config_overrides.recheck_seconds', $this->probe->reads());
    }

    public function test_the_static_audit_reports_the_same_key_where_literal_and_says_so_when_dynamic(): void
    {
        $audit = new BootReadConfigAudit(app(ConfigSectionRegistry::class), [BootReadFixtureProvider::class]);
        $messages = $this->messages($audit->run());

        $this->assertStringContainsString('widgets.enabled is declared live, but `widgets.enabled` is read during boot in '.BootReadFixtureProvider::class.'::boot', $messages);
        $this->assertStringNotContainsString('`widgets.driver`', $messages, 'a deploy key is not a finding');
        $this->assertStringContainsString('1 config read(s) during boot use a dynamic key', $messages);
    }

    public function test_an_ancestor_read_touches_the_live_keys_below_it(): void
    {
        $this->assertSame(['widgets.enabled'], \Splicewire\Beam\Config\ConfigLiveKeys::touchedBy('widgets', \Splicewire\Beam\Config\ConfigLiveKeys::in(app(ConfigSectionRegistry::class))));
        $this->assertSame([], \Splicewire\Beam\Config\ConfigLiveKeys::touchedBy('widgets.limits', \Splicewire\Beam\Config\ConfigLiveKeys::in(app(ConfigSectionRegistry::class))));
    }

    public function test_both_audits_run_under_the_doctor_and_stay_advisory(): void
    {
        $registrations = app(BeamDoctorManifest::class)->registrations();
        $audits = array_column(array_map(fn ($registration) => [$registration->audit, $registration], $registrations), 1, 0);

        foreach ([ConfigSectionCoverageAudit::class, BootReadConfigAudit::class] as $audit) {
            $this->assertArrayHasKey($audit, $audits);
            $this->assertFalse($audits[$audit]->gate, "{$audit} is report-only until ticket 13");
        }
    }
}
