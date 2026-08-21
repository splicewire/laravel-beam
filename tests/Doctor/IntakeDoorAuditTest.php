<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Doctor\IntakeDoorAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see IntakeDoorAudit} — beam-facade ticket 53. Every case here is a state the estate has actually
 * been in: eight of nine hosts stood at "door mounted, registry empty" before ticket 48, ticket 61
 * re-stemmed a registered `$id` (the 404 case is what a one-sided re-stem looks like), and the
 * allow-list is deny-by-default, so registering the artifact and forgetting `public_schemas` yields a
 * live door that refuses everything.
 *
 * The two container-only tests deliberately do NOT boot testbench: an unmounted door is the estate's
 * majority state and building it by *not* configuring one is the honest fixture.
 */
class IntakeDoorAuditTest extends TestCase
{
    private const STEM = 'https://schemas.splicewire.app/test/intake-door-audit';

    private string $registryDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Mount the opt-in door. Read at boot, so it must be set pre-boot; the slug map and the
        // allow-list are read per run() and each test sets its own.
        $app['config']->set('beam.core.intake.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registryDir = sys_get_temp_dir().'/intake-door-audit-'.uniqid();
        @mkdir($this->registryDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->registryDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->registryDir);

        parent::tearDown();
    }

    public function test_it_skips_when_no_door_is_mounted(): void
    {
        $findings = (new IntakeDoorAudit($this->bareContainer()))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString(IntakeDoorAudit::ROUTE, $findings[0]->detail);
    }

    public function test_it_warns_when_the_door_is_mounted_and_nothing_can_resolve_a_schema(): void
    {
        $container = $this->bareContainer();
        $router = $container->make('router');
        $router->post('beam/intake/{schema}', fn () => null)->name(IntakeDoorAudit::ROUTE);
        // A name applied after the route is collected is only in the lookup once refreshed. A booted
        // app does this for itself; a bare RouteCollection does not.
        $router->getRoutes()->refreshNameLookups();

        $findings = (new IntakeDoorAudit($container))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString(SchemaRegistry::class, $findings[0]->detail);
    }

    public function test_it_warns_when_the_door_is_mounted_over_an_empty_registry(): void
    {
        $this->bindRegistry();

        $findings = (new IntakeDoorAudit($this->app))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('EMPTY', $findings[0]->detail);

        // 48's lesson: the location is reported off the resolved OBJECT, not the config key that was
        // meant to produce it — a host whose registry silently resolved to the gitignored package
        // default would otherwise read as conformant.
        $this->assertStringContainsString($this->registryDir, $findings[0]->detail);
    }

    public function test_it_passes_when_every_declared_slug_resolves_and_is_allow_listed(): void
    {
        $this->bindRegistry()->register($this->artifact(self::STEM.'/1'));

        config()->set('beam.core.intake.forms', ['feedback' => self::STEM]);
        config()->set('beam.core.intake.public_schemas', [self::STEM]);

        $findings = (new IntakeDoorAudit($this->app))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('feedback → '.self::STEM, $findings[0]->detail);
    }

    public function test_it_warns_per_slug_when_a_stem_has_no_registered_version(): void
    {
        // The shape a one-sided re-stem leaves behind (ticket 61): the artifact is registered, the
        // slug points at a stem nothing answers to, and the door 404s every submission.
        $this->bindRegistry()->register($this->artifact(self::STEM.'/1'));

        config()->set('beam.core.intake.forms', [
            'feedback' => self::STEM,
            'moved' => self::STEM.'-elsewhere',
        ]);
        config()->set('beam.core.intake.public_schemas', [self::STEM, self::STEM.'-elsewhere']);

        $findings = (new IntakeDoorAudit($this->app))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('POST /beam/intake/moved 404s', $findings[0]->detail);
    }

    public function test_it_warns_when_a_resolvable_slug_is_not_on_the_allow_list(): void
    {
        $this->bindRegistry()->register($this->artifact(self::STEM.'/1'));

        config()->set('beam.core.intake.forms', ['feedback' => self::STEM]);
        config()->set('beam.core.intake.public_schemas', []);

        $findings = (new IntakeDoorAudit($this->app))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('403s', $findings[0]->detail);
    }

    public function test_it_reports_the_registry_when_the_door_declares_no_slugs(): void
    {
        // A host may pass a stem straight down the URL, so there is no configured population to
        // resolve ahead of a request — reported, never asserted against.
        $this->bindRegistry()->register($this->artifact(self::STEM.'/1'));

        config()->set('beam.core.intake.forms', []);

        $findings = (new IntakeDoorAudit($this->app))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('declares no slugs', $findings[0]->detail);
    }

    private function bindRegistry(): FilesystemSchemaRegistry
    {
        $registry = new FilesystemSchemaRegistry($this->registryDir);

        $this->app->singleton(SchemaRegistry::class, fn () => $registry);

        return $registry;
    }

    /** @return array<string, mixed> */
    private function artifact(string $id): array
    {
        return [
            '$id' => $id,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
        ];
    }

    /** A container with a router and an empty config, and nothing else — no door, no registry. */
    private function bareContainer(): Container
    {
        $container = new Container;
        $container->instance('config', new Repository([]));
        $container->instance('router', new Router(new Dispatcher($container), $container));

        return $container;
    }
}
