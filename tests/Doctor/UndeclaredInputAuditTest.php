<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\UndeclaredInputAudit;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The count that replaced the flip's memory (api-surface-coherence 117).
 *
 * The load-bearing assertion is {@see test_the_saved_filters_sub_surface_does_not_inflate_the_count}:
 * the resource axis counts ROUTES that reach `ParticleController::parseInput()`, not declarations. At
 * the flagship the difference was 23 versus 1 — an audit that read the attribute would have cried wolf
 * about 22 mounts that validate their own input.
 */
class UndeclaredInputAuditTest extends TestCase
{
    private function audit(): UndeclaredInputAudit
    {
        return new UndeclaredInputAudit(
            app(Router::class),
            app(ParticleResourceRegistry::class),
            app(ParticleOperationRegistry::class),
        );
    }

    private function resource(string $key, string|false|null $input): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: BeamParticle::class,
            input: $input,
            frame: false,
        ));
    }

    private function operation(string $resource, string $name, string|false|null $input): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: OperationKind::Write,
            model: BeamParticle::class,
            handle: fn () => null,
            input: $input,
        ));
    }

    /** A generic-controller write mount — the shape `Particle::mount()` stamps for `store`/`update`. */
    private function writeMount(string $uri, string $key, string $verb = 'post'): void
    {
        app(Router::class)->{$verb}($uri, [ParticleController::class, $verb === 'post' ? 'store' : 'update'])
            ->defaults(ParticleController::RESOURCE, $key);
    }

    private function finding(array $findings, string $check): object
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        $this->fail("No finding for check {$check}.");
    }

    public function test_it_reports_both_axes_as_separate_checks(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(2, $findings);
        $this->assertSame(
            [UndeclaredInputAudit::CHECK_RESOURCES, UndeclaredInputAudit::CHECK_OPERATIONS],
            array_map(fn ($finding) => $finding->check, $findings),
        );
    }

    public function test_a_host_with_no_write_mounts_passes_rather_than_reporting_nothing(): void
    {
        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_a_read_only_mount_is_not_a_write_mount(): void
    {
        $this->resource('widgets', null);
        app(Router::class)->get('widgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'widgets');
        app(Router::class)->delete('widgets/{id}', [ParticleController::class, 'destroy'])
            ->defaults(ParticleController::RESOURCE, 'widgets');

        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('nothing to declare', $finding->detail);
    }

    public function test_an_undeclared_resource_reached_by_a_write_mount_warns(): void
    {
        $this->resource('widgets', null);
        $this->writeMount('widgets', 'widgets');

        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('1 of 1 reachable particle write mount', $finding->detail);
        $this->assertStringContainsString('POST widgets', $finding->detail);
    }

    public function test_an_explicit_false_is_a_declaration_and_passes(): void
    {
        $this->resource('widgets', false);
        $this->writeMount('widgets', 'widgets');

        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('1 reachable particle write mount', $finding->detail);
    }

    /**
     * The reason this audit is route-side. `ResourceFiltersController` extends plain `Controller`,
     * declares its own input DTOs and never calls `parseInput()` — so its 22 flagship mounts must not
     * appear here even though they carry the `_particle` stamp of an `input: null` resource.
     */
    public function test_the_saved_filters_sub_surface_does_not_inflate_the_count(): void
    {
        $this->resource('widgets', null);
        app(Router::class)->post('widgets/filters', [ResourceFiltersController::class, 'store'])
            ->defaults(ParticleController::RESOURCE, 'widgets');
        app(Router::class)->put('widgets/filters/{id}', [ResourceFiltersController::class, 'update'])
            ->defaults(ParticleController::RESOURCE, 'widgets');

        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('nothing to declare', $finding->detail);
    }

    /** A resource that later gains a write mount re-enters the count with no list to edit. */
    public function test_the_reachable_set_is_derived_per_run(): void
    {
        $this->resource('widgets', null);

        $this->assertSame(
            DoctorStatus::Pass,
            $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES)->status,
        );

        $this->writeMount('widgets/{id}', 'widgets', 'put');

        $this->assertSame(
            DoctorStatus::Warn,
            $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_RESOURCES)->status,
        );
    }

    public function test_an_undeclared_operation_warns_on_its_own_axis(): void
    {
        $this->operation('widgets', 'publish', null);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $this->finding($findings, UndeclaredInputAudit::CHECK_OPERATIONS)->status);
        $this->assertStringContainsString('widgets.publish', $this->finding($findings, UndeclaredInputAudit::CHECK_OPERATIONS)->detail);
        // The axes are decoupled: an op-axis warn must not drag the resource axis with it.
        $this->assertSame(DoctorStatus::Pass, $this->finding($findings, UndeclaredInputAudit::CHECK_RESOURCES)->status);
    }

    public function test_an_acknowledged_operation_is_reported_as_acknowledged_not_outstanding(): void
    {
        $this->operation('media', 'ingest', null);

        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_OPERATIONS);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('acknowledged carve-out', $finding->detail);
        $this->assertStringContainsString('media.ingest', $finding->detail);
        $this->assertArrayHasKey('media.ingest', UndeclaredInputAudit::ACKNOWLEDGED);
    }

    /** The carve-out cannot outlive its reason. */
    public function test_an_acknowledgement_whose_operation_now_declares_is_reported_stale(): void
    {
        $this->operation('media', 'ingest', false);

        $finding = $this->finding($this->audit()->run(), UndeclaredInputAudit::CHECK_OPERATIONS);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('STALE acknowledgement', $finding->detail);
    }
}
