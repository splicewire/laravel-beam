<?php

namespace Splicewire\Beam\Tests\Console;

use Splicewire\Beam\Doctor\EnvPrefixAudit;
use Splicewire\Beam\Doctor\Support\EnvPrefixCensus;
use Splicewire\Beam\Tests\Doctor\Support\EnvPrefixFixtures;
use Splicewire\Beam\Tests\TestCase;

/**
 * env-inventory 02: `splicewire:beam:env-prefix-baseline` previews without `--write`, captures on the first write, only
 * shrinks after that, and writes only for the checkout's own package. The census is bound to a disposable copy of the
 * `acme/widgets` fixture, so nothing committed is touched.
 */
class EnvPrefixBaselineCommandTest extends TestCase
{
    private EnvPrefixFixtures $fixtures;

    /** @var array<string, string> */
    private array $packages;

    private string $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new EnvPrefixFixtures;
        $this->packages = $this->fixtures->copy('acme-widgets', 'acme-gizmos');
        $this->baseline = $this->packages['acme/widgets'].'/'.EnvPrefixCensus::BASELINE;
        $this->app->instance(EnvPrefixCensus::class, new EnvPrefixCensus($this->packages, 'acme/widgets'));
    }

    protected function tearDown(): void
    {
        $this->fixtures->reap();
        parent::tearDown();
    }

    private function addRead(string $line): void
    {
        $config = $this->packages['acme/widgets'].'/config/widgets.php';
        file_put_contents($config, str_replace("];\n", "    {$line}\n];\n", (string) file_get_contents($config)));
    }

    /** @return list<string> */
    private function written(): array
    {
        return EnvPrefixCensus::baseline($this->packages['acme/widgets']) ?? [];
    }

    public function test_without_write_it_prints_the_baseline_and_writes_nothing(): void
    {
        $this->artisan('splicewire:beam:env-prefix-baseline')
            ->expectsOutputToContain('LEGACY_WIDGET_MODE')
            ->expectsOutputToContain('Nothing written')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->baseline);
    }

    public function test_the_first_write_captures_exactly_the_non_conforming_names_and_the_audit_then_passes(): void
    {
        $this->artisan('splicewire:beam:env-prefix-baseline', ['--write' => true])->assertSuccessful();

        $this->assertSame(['LEGACY_WIDGET_MODE'], $this->written());
        $this->assertStringContainsString('splicewire:beam:env-prefix-baseline --write', (string) file_get_contents($this->baseline));

        $widgetsOnly = new EnvPrefixAudit(new EnvPrefixCensus(['acme/widgets' => $this->packages['acme/widgets']], 'acme/widgets'));
        $this->assertSame(['pass'], array_map(fn ($finding): string => $finding->status->value, $widgetsOnly->run()));
    }

    public function test_a_later_write_refuses_a_joining_name_and_leaves_the_file_alone(): void
    {
        $this->artisan('splicewire:beam:env-prefix-baseline', ['--write' => true])->assertSuccessful();
        $before = (string) file_get_contents($this->baseline);
        $this->addRead("'fresh' => env('FRESH_KNOB'),");

        $this->artisan('splicewire:beam:env-prefix-baseline', ['--write' => true])
            ->expectsOutputToContain('FRESH_KNOB')
            ->assertFailed();

        $this->assertSame($before, (string) file_get_contents($this->baseline));
    }

    public function test_a_later_write_drops_a_name_that_is_no_longer_read(): void
    {
        file_put_contents($this->baseline, EnvPrefixCensus::renderBaseline('acme/widgets', ['GONE_KNOB', 'LEGACY_WIDGET_MODE']));

        $this->artisan('splicewire:beam:env-prefix-baseline', ['--write' => true])
            ->expectsOutputToContain('GONE_KNOB')
            ->assertSuccessful();

        $this->assertSame(['LEGACY_WIDGET_MODE'], $this->written());
    }

    public function test_another_package_is_written_only_when_it_is_linked_from_its_own_git_checkout(): void
    {
        $gizmos = $this->packages['acme/gizmos'];

        $this->artisan('splicewire:beam:env-prefix-baseline', ['--package' => 'acme/gizmos', '--write' => true])
            ->expectsOutputToContain('acme/gizmos')
            ->assertFailed();
        $this->assertFileDoesNotExist($gizmos.'/'.EnvPrefixCensus::BASELINE);

        mkdir($gizmos.'/.git');

        $this->artisan('splicewire:beam:env-prefix-baseline', ['--package' => 'acme/gizmos', '--write' => true])->assertSuccessful();
        $this->assertSame(['WIDGETS_SHARED'], EnvPrefixCensus::baseline($gizmos));
        $this->assertFileDoesNotExist($this->baseline);
    }

    public function test_it_writes_only_for_an_installed_family_package(): void
    {
        $this->app->instance(EnvPrefixCensus::class, new EnvPrefixCensus($this->packages, 'acme/app'));

        $this->artisan('splicewire:beam:env-prefix-baseline', ['--write' => true])
            ->expectsOutputToContain('acme/app')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->baseline);
        $this->assertFileDoesNotExist($this->packages['acme/gizmos'].'/'.EnvPrefixCensus::BASELINE);
    }
}
