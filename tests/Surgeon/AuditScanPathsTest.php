<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\AuditScanPaths;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The audit scan-path contribution seam: a package's provider pushes `(controllersDir, routesDir)` pairs
 * into the boot-time {@see AuditScanPaths} singleton and the bypass/redundancy/house-style sweeps fold
 * them in alongside the host dirs — SchemaSources' self-registration idiom turned on audit scope. The
 * end-to-end proves a PACKAGE-DIR controller (a PlanController-shaped bespoke CRUD lister, mounted by a
 * package route file) is flagged by a sweep that never named the package.
 */
class AuditScanPathsTest extends TestCase
{
    private string $controllersDir;

    private string $routesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/audit-scan-'.uniqid();
        $this->controllersDir = $base.'/Http/Controllers';
        $this->routesDir = $base.'/routes';
        @mkdir($this->controllersDir, 0775, true);
        @mkdir($this->routesDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->controllersDir, $this->routesDir] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_registrations_accumulate_and_project_dir_lists(): void
    {
        $paths = (new AuditScanPaths)
            ->register('splicewire/laravel-beam-commerce', '/pkg/commerce/src/Http', '/pkg/commerce/routes')
            ->register('splicewire/laravel-beam-market', '/pkg/market/src/Http', '/pkg/market/routes')
            ->register('splicewire/laravel-beam-market', '/pkg/market/src/Http', '/pkg/market/routes');

        $this->assertCount(3, $paths->paths());
        $this->assertSame('splicewire/laravel-beam-commerce', $paths->paths()[0]['package']);
        // The dir projections deduplicate (market registered twice) and keep registration order.
        $this->assertSame(['/pkg/commerce/src/Http', '/pkg/market/src/Http'], $paths->controllersDirs());
        $this->assertSame(['/pkg/commerce/routes', '/pkg/market/routes'], $paths->routesDirs());
    }

    public function test_the_provider_binds_the_singleton(): void
    {
        $this->assertTrue($this->app->bound(AuditScanPaths::class));
        $this->assertSame(
            $this->app->make(AuditScanPaths::class),
            $this->app->make(AuditScanPaths::class),
        );
    }

    public function test_a_contributed_package_controller_joins_the_redundancy_sweep(): void
    {
        // A package-shipped bespoke CRUD lister (the PlanController shape) + the route file mounting it.
        file_put_contents($this->controllersDir.'/PlanController.php', <<<'PHP'
        <?php
        namespace Acme\Billing\Http\Controllers;

        use Acme\Billing\Plan;

        class PlanController extends Controller
        {
            public function index()
            {
                return Plan::orderBy('name')->get();
            }
        }
        PHP);
        file_put_contents($this->routesDir.'/operator.php', <<<'PHP'
        <?php
        use Acme\Billing\Http\Controllers\PlanController;
        use Illuminate\Support\Facades\Route;

        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        PHP);

        // The package's provider would push this pair at boot; `plans` is a registered resource.
        $this->app->make(AuditScanPaths::class)
            ->register('acme/billing', $this->controllersDir, $this->routesDir);

        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'plans', backing: 'Acme\\Billing\\Plan'));

        $findings = ParticleControllerRedundancyAudit::forRoutes(registry: $registry)->suggestOperations();

        $flagged = array_filter(
            $findings,
            fn ($f) => str_contains($f->finding->detail, 'PlanController') && str_contains($f->finding->detail, '[plans]'),
        );
        $this->assertCount(1, $flagged, 'the contributed package controller should surface in the sweep');
        $this->assertSame('warn', array_values($flagged)[0]->finding->status->value);
    }
}
