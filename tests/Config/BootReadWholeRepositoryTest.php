<?php

namespace Splicewire\Beam\Tests\Config;

use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Doctor\BootReadConfigAudit;
use Splicewire\Beam\Testing\BootReadProbe;
use Splicewire\Beam\Testing\BootReadRecordingRepository;
use Splicewire\Beam\Tests\Fixtures\Config\GaugesSection;
use Splicewire\Beam\Tests\Fixtures\Config\WholeConfigReadFixtureProvider;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * Whole-repository reads count (06 amend; review-r1's HIGH and build-qa's qa06 case 1, 2026-11-16): a read that returns a
 * subtree is a read of every live key inside it, so `config()->all()` and `config()->get(null)` touch every live key, as
 * an ancestor read (`get('widgets')`) and array access already did.
 */
class BootReadWholeRepositoryTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    private BootReadProbe $probe;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), WholeConfigReadFixtureProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('widgets', require self::FIXTURES.'/package/widgets.php');
    }

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
        app(ConfigSectionRegistry::class)->section(WidgetsSection::class, owner: 'acme/widgets');
    }

    /** build-qa (ConfigSections06BoundaryQaTest, 2026-11-16; credited): a provider holding a live key taken from all(). */
    public function test_qa_probe_detects_a_provider_holding_a_live_key_from_all_config(): void
    {
        $this->assertSame((bool) config('widgets.enabled'), WholeConfigReadFixtureProvider::$held);
        $this->assertArrayHasKey('widgets.driver', $this->probe->reads(), 'the installed probe records ordinary reads');

        $violations = implode("\n", $this->probe->violations(app(ConfigSectionRegistry::class)));

        $this->assertStringContainsString('widgets.enabled is declared live but was read during boot (as the whole configuration', $violations);
        $this->assertStringContainsString(WholeConfigReadFixtureProvider::class.'::boot', $violations);
    }

    /** review-r1's repro shapes, read through the recording repository by a non-framework caller. */
    public function test_every_read_shape_that_returns_a_live_key_is_a_violation(): void
    {
        $registry = app(ConfigSectionRegistry::class);
        $shapes = [
            'direct' => fn (BootReadRecordingRepository $r): mixed => $r->get('widgets.enabled'),
            'ancestor' => fn (BootReadRecordingRepository $r): mixed => $r->get('widgets')['enabled'],
            'array access' => fn (BootReadRecordingRepository $r): mixed => $r['widgets']['enabled'],
            'all()' => fn (BootReadRecordingRepository $r): mixed => $r->all()['widgets']['enabled'],
            'get(null)' => fn (BootReadRecordingRepository $r): mixed => $r->get(null)['widgets']['enabled'],
            'has()' => fn (BootReadRecordingRepository $r): mixed => $r->has('widgets.enabled'),
        ];

        foreach ($shapes as $shape => $read) {
            $probe = new BootReadProbe;
            $read(new BootReadRecordingRepository(['widgets' => ['enabled' => true, 'driver' => 'array']], $probe));
            $probe->stop();

            $this->assertNotSame([], $probe->violations($registry), "{$shape} reads the live widgets.enabled");
        }

        // A deploy key read is not a violation, and a whole read finds nothing in a section that declares no live key.
        $probe = new BootReadProbe;
        (new BootReadRecordingRepository(['widgets' => ['driver' => 'array']], $probe))->get('widgets.driver');
        $this->assertSame([], $probe->violations($registry));

        $deployOnly = new ConfigSectionRegistry;
        $deployOnly->section(GaugesSection::class, owner: 'host');
        $probe = new BootReadProbe;
        (new BootReadRecordingRepository(['fleet' => []], $probe))->all();
        $this->assertSame([], $probe->violations($deployOnly));
    }

    public function test_the_static_audit_reports_a_whole_configuration_read_during_boot(): void
    {
        $audit = new BootReadConfigAudit(app(ConfigSectionRegistry::class), [WholeConfigReadFixtureProvider::class]);
        $messages = implode("\n", array_map(fn ($finding): string => $finding->detail, $audit->run()));

        $this->assertStringContainsString('widgets.enabled is declared live, but the whole configuration (all() / get(null)) is read during boot in '.WholeConfigReadFixtureProvider::class.'::boot', $messages);
        $this->assertStringNotContainsString('`widgets.driver`', $messages);
    }
}
