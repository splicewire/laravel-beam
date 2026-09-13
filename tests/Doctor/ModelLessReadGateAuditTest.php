<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Doctor\ModelLessReadGateAudit;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see ModelLessReadGateAudit} — the count that makes app ADR-0119 §2's standing posture a measurement
 * rather than a memory: which model-less resources declare no read gate at all.
 *
 * Built on a fresh registry so the population is exactly the fixture; each posture has a row, so an audit
 * that reported every model-less resource (or none) fails one assertion or the other.
 */
class ModelLessReadGateAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = new ParticleResourceRegistry;
    }

    private function declare(string $key, string $backing, ?string $policy = null, ?string $section = null, array $realms = []): void
    {
        $this->resources->register(new ParticleResource(
            key: $key,
            backing: $backing,
            data: WidgetGateData::class,
            filterable: false,
            label: $section === null ? '' : ucfirst($key),
            policy: $policy,
            section: $section,
            readOnly: true,
            showable: false,
        ), $realms);
    }

    private function audit(): ModelLessReadGateAudit
    {
        return new ModelLessReadGateAudit($this->resources, new ResourceVisibility($this->resources, app(\Illuminate\Contracts\Auth\Access\Gate::class)));
    }

    public function test_an_empty_registry_is_inconclusive_rather_than_clean(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertSame(ModelLessReadGateAudit::CHECK, $findings[0]->check);
    }

    public function test_an_undeclared_model_less_resource_warns_by_name_and_never_fails(): void
    {
        $this->declare('queue', AuditOpenFeed::class, section: 'threads', realms: ['tenant']);
        $this->declare('gated', AuditOpenFeed::class, policy: 'queue.read');
        $this->declare('modelled', BeamSchema::class);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('[queue]', $findings[0]->detail);
        $this->assertStringContainsString('section [threads]', $findings[0]->detail);
        $this->assertStringContainsString('realms [tenant]', $findings[0]->detail);
        $this->assertStringNotContainsString('[gated]', $findings[0]->detail);
        $this->assertStringNotContainsString('[modelled]', $findings[0]->detail);
    }

    public function test_every_model_less_resource_declaring_an_ability_passes_with_the_count(): void
    {
        $this->declare('gated', AuditOpenFeed::class, policy: 'queue.read');
        $this->declare('also-gated', AuditOpenFeed::class, policy: 'entitlement:queue');
        $this->declare('modelled', BeamSchema::class, policy: BeamSchema::class);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertStringContainsString('2 model-less resources read', $findings[0]->detail);
    }

    /**
     * A policy CLASS on a model-less declaration counts as declared but cannot mean what its author meant
     * — it names no ability, so everyone but a superuser is refused. Warned by name, separately from the
     * undeclared row; a model-backed resource carrying the same class-string (its legitimate use) is not.
     */
    public function test_a_policy_class_on_a_model_less_resource_warns_by_name(): void
    {
        $this->declare('classy', AuditOpenFeed::class, policy: BeamSchema::class);
        $this->declare('modelled', BeamSchema::class, policy: BeamSchema::class);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('policy CLASS', $findings[0]->detail);
        $this->assertStringContainsString('[classy]', $findings[0]->detail);
        $this->assertStringNotContainsString('[modelled]', $findings[0]->detail);
    }

    public function test_a_registry_with_no_model_less_resource_passes_and_says_so(): void
    {
        $this->declare('modelled', BeamSchema::class);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('none is model-less', $findings[0]->detail);
    }

    /**
     * Registered where `splicewire:beam:doctor` runs it: a model-less resource planted in the BOOTED
     * registry surfaces by key in the command's output. A mention of the class anywhere else (a comment,
     * an unreached branch) cannot satisfy this.
     */
    public function test_the_doctor_command_runs_it(): void
    {
        $base = sys_get_temp_dir().'/beam-doctor-modelless-'.uniqid();
        @mkdir($base, 0777, true);
        file_put_contents($base.'/composer.json', json_encode(['minimum-stability' => 'dev', 'prefer-stable' => true]));
        file_put_contents($base.'/composer.lock', json_encode(['packages' => [], 'packages-dev' => []]));
        file_put_contents($base.'/BEAM.md', "---\nsatellite: fixture\nvariant: inertia-react\n---\n# Fixture\n");
        $this->app->setBasePath($base);

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'doctor-probe-feed',
            backing: AuditOpenFeed::class,
            data: WidgetGateData::class,
            filterable: false,
            readOnly: true,
            showable: false,
        ));

        try {
            $this->artisan('splicewire:beam:doctor')->expectsOutputToContain('no read gate: [doctor-probe-feed]');
        } finally {
            foreach (['composer.json', 'composer.lock', 'BEAM.md'] as $file) {
                @unlink($base.'/'.$file);
            }
            @rmdir($base);
        }
    }
}

class AuditOpenFeed implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}
