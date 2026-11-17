<?php

namespace Splicewire\Beam\Tests\Doctor;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\EnvPrefixAudit;
use Splicewire\Beam\Doctor\Support\EnvPrefixCensus;
use Splicewire\Beam\Tests\Doctor\Support\EnvPrefixFixtures;

/**
 * env-inventory 02: the env-prefix audit over disposable copies of two fixture packages. `acme/widgets` reads
 * `WIDGETS_*`, the third-party `STRIPE_SECRET` and one non-conforming `LEGACY_WIDGET_MODE`; `acme/gizmos` also reads
 * `WIDGETS_SHARED`, with a different default.
 */
class EnvPrefixAuditTest extends TestCase
{
    private EnvPrefixFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new EnvPrefixFixtures;
    }

    protected function tearDown(): void
    {
        $this->fixtures->reap();
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $packages
     * @return list<Finding>
     */
    private function audit(array $packages): array
    {
        return (new EnvPrefixAudit(new EnvPrefixCensus($packages, 'acme/widgets')))->run();
    }

    /** @param list<Finding> $findings */
    private function messages(array $findings): string
    {
        return implode("\n", array_map(fn (Finding $finding): string => "[{$finding->status->value}] {$finding->detail}", $findings));
    }

    /** @param array<string, string> $packages */
    private function writeBaseline(array $packages, string $package): void
    {
        $scan = EnvPrefixCensus::readsOf($package, $packages[$package]);
        file_put_contents($packages[$package].'/'.EnvPrefixCensus::BASELINE, EnvPrefixCensus::renderBaseline($package, EnvPrefixCensus::unruled($scan['reads'])));
    }

    public function test_a_package_whose_baseline_was_just_written_has_zero_findings_and_the_population_is_named(): void
    {
        $packages = $this->fixtures->copy('acme-widgets');
        $this->writeBaseline($packages, 'acme/widgets');

        $findings = $this->audit($packages);

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertSame(EnvPrefixAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('1 family package(s), 2 config file(s), 5 env() read(s)', $findings[0]->detail);
        $this->assertStringContainsString('(1 grandfathered)', $findings[0]->detail);
    }

    public function test_a_non_conforming_env_call_added_to_a_fixture_config_is_reported(): void
    {
        $packages = $this->fixtures->copy('acme-widgets');
        $this->writeBaseline($packages, 'acme/widgets');
        $config = $packages['acme/widgets'].'/config/widgets.php';
        file_put_contents($config, str_replace("];\n", "    'fresh' => env('FRESH_KNOB', 'x'),\n];\n", (string) file_get_contents($config)));

        $findings = $this->audit($packages);
        $failed = array_values(array_filter($findings, fn (Finding $finding): bool => $finding->status === DoctorStatus::Fail));

        $this->assertCount(1, $failed, $this->messages($findings));
        $this->assertStringContainsString('acme/widgets: 1 env name(s)', $failed[0]->detail);
        $this->assertStringContainsString('FRESH_KNOB (config/widgets.php:10)', $failed[0]->detail);
        $this->assertStringContainsString('are not in its env-prefix-baseline.txt', $failed[0]->detail);
        $this->assertStringNotContainsString('LEGACY_WIDGET_MODE', $failed[0]->detail);
    }

    public function test_without_a_baseline_each_non_conforming_name_fails_and_the_command_is_named(): void
    {
        $findings = $this->audit($this->fixtures->copy('acme-widgets'));

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('LEGACY_WIDGET_MODE (config/widgets.php:8)', $findings[0]->detail);
        $this->assertStringContainsString('splicewire:beam:env-prefix-baseline --package=acme/widgets --write', $findings[0]->detail);
        // Its own prefix, a nested file's own prefix and a declared third-party name all conform.
        $this->assertStringNotContainsString('WIDGETS_', $findings[0]->detail);
        $this->assertStringNotContainsString('STRIPE_SECRET', $findings[0]->detail);
    }

    public function test_a_name_in_another_packages_prefix_and_a_name_read_with_two_defaults_are_reported(): void
    {
        $packages = $this->fixtures->copy('acme-widgets', 'acme-gizmos');
        $this->writeBaseline($packages, 'acme/widgets');

        $messages = $this->messages($this->audit($packages));

        $this->assertStringContainsString('[fail] acme/gizmos: 1 env name(s)', $messages);
        $this->assertStringContainsString('[warn] acme/gizmos: 1 env name(s) sit in another installed package\'s prefix', $messages);
        $this->assertStringContainsString('WIDGETS_SHARED (config/gizmos.php:7; acme/widgets owns WIDGETS_)', $messages);
        $this->assertStringContainsString('[warn] WIDGETS_SHARED is read by 2 packages with different defaults', $messages);
        $this->assertStringContainsString('acme/gizmos config/gizmos.php:7 → false', $messages);
        $this->assertStringContainsString('acme/widgets config/widgets.php:7 → true', $messages);
    }

    public function test_grandfathering_a_name_silences_its_spelling_and_prefix_but_never_two_defaults(): void
    {
        $packages = $this->fixtures->copy('acme-widgets', 'acme-gizmos');
        $this->writeBaseline($packages, 'acme/widgets');
        $this->writeBaseline($packages, 'acme/gizmos');

        $findings = $this->audit($packages);

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringStartsWith('WIDGETS_SHARED is read by 2 packages with different defaults', $findings[0]->detail);
    }

    public function test_defaults_that_differ_only_in_spelling_are_not_two_defaults(): void
    {
        $packages = $this->fixtures->copy('acme-widgets', 'acme-gizmos');
        $gizmos = $packages['acme/gizmos'].'/config/gizmos.php';
        file_put_contents($gizmos, str_replace("env('WIDGETS_SHARED', false)", "env('WIDGETS_SHARED', TRUE)", (string) file_get_contents($gizmos)));
        $widgets = $packages['acme/widgets'].'/config/widgets.php';
        file_put_contents($widgets, str_replace("env('LEGACY_WIDGET_MODE', 'a')", "env('LEGACY_WIDGET_MODE', \"a\")", (string) file_get_contents($widgets)));
        file_put_contents($gizmos, str_replace("];\n", "    'mode' => env('LEGACY_WIDGET_MODE', 'a'),\n    'none' => env('GIZMOS_NONE'),\n];\n", (string) file_get_contents($gizmos)));
        file_put_contents($widgets, str_replace("];\n", "    'none' => env('GIZMOS_NONE', null),\n];\n", (string) file_get_contents($widgets)));

        $messages = $this->messages($this->audit($packages));

        $this->assertStringNotContainsString('with different defaults', $messages);
        // …the shared reads are still reported for their prefix, so the reads were seen.
        $this->assertStringContainsString('WIDGETS_SHARED (config/gizmos.php:7; acme/widgets owns WIDGETS_)', $messages);
    }

    public function test_a_baselined_name_that_is_no_longer_read_is_reported(): void
    {
        $packages = $this->fixtures->copy('acme-widgets');
        file_put_contents($packages['acme/widgets'].'/'.EnvPrefixCensus::BASELINE, EnvPrefixCensus::renderBaseline('acme/widgets', ['GONE_KNOB', 'LEGACY_WIDGET_MODE']));

        $findings = $this->audit($packages);

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('acme/widgets: 1 name(s) in its env-prefix-baseline.txt are no longer read by its config: GONE_KNOB', $findings[0]->detail);
    }

    public function test_the_audit_never_writes_the_baseline(): void
    {
        $packages = $this->fixtures->copy('acme-widgets', 'acme-gizmos');
        $this->writeBaseline($packages, 'acme/widgets');
        $before = array_map(EnvPrefixFixtures::snapshot(...), $packages);

        $this->audit($packages);
        $this->audit($packages);

        $this->assertSame($before, array_map(EnvPrefixFixtures::snapshot(...), $packages));
        $this->assertFileDoesNotExist($packages['acme/gizmos'].'/'.EnvPrefixCensus::BASELINE, 'a package with no baseline must not get one from the audit');

        // …and structurally: the audit's source has no write path at all.
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Doctor/EnvPrefixAudit.php');
        $this->assertDoesNotMatchRegularExpression('/\b(file_put_contents|fopen|fwrite|touch|rename|copy|unlink|mkdir)\s*\(|->put\(/', $source);
    }

    public function test_with_no_family_package_installed_the_audit_says_it_read_nothing(): void
    {
        $findings = $this->audit([]);

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('no env() name was read', $findings[0]->detail);
    }
}
