<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\EventCatalogPrefixAudit;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\ResourceKeyOracle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The advisory that replaced a boot-fatal throw (api-surface-coherence ticket 91).
 *
 * The load-bearing assertion in this file is {@see test_a_dead_prefix_warns_and_never_fails}: `Warn`, not
 * `Fail`, is the entire fix. `Fail` here would only move tower's outage from `artisan --version` to
 * `splicewire:beam:doctor`'s exit code, which is a smaller outage rather than a different decision.
 */
class EventCatalogPrefixAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: BeamParticle::class,
            frame: false,
        ));
    }

    private function auditOver(EventType ...$types): EventCatalogPrefixAudit
    {
        $registry = new EventTypeRegistry(new ResourceKeyOracle($this->app));

        foreach ($types as $type) {
            $registry->register($type);
        }

        return new EventCatalogPrefixAudit($registry);
    }

    public function test_an_empty_catalog_passes_rather_than_reporting_nothing(): void
    {
        $findings = $this->auditOver()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(EventCatalogPrefixAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('empty catalog', $findings[0]->detail);
    }

    public function test_a_catalog_whose_prefixes_all_resolve_passes(): void
    {
        $findings = $this->auditOver(
            new EventType('widgets.provisioned', subject: BeamParticle::class),
            new EventType('widgets.render.completed', subject: BeamParticle::class),
        )->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('2 event types', $findings[0]->detail);
    }

    public function test_a_dead_prefix_warns_and_never_fails(): void
    {
        $findings = $this->auditOver(
            new EventType('widgets.provisioned', subject: BeamParticle::class),
            new EventType('sprockets.provisioned', subject: BeamParticle::class),
        )->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertNotSame(DoctorStatus::Fail, $findings[0]->status);
    }

    /**
     * Every offender by name, not a count — the finding is a work-list. tower's live shape is four
     * `compositions.*` names against one absent resource, and "4 event types have a dead prefix" would
     * start a hunt rather than end one.
     */
    public function test_the_warning_names_every_offender_and_the_live_keys(): void
    {
        $findings = $this->auditOver(
            new EventType('widgets.provisioned', subject: BeamParticle::class),
            new EventType('sprockets.provisioned', subject: BeamParticle::class),
            new EventType('sprockets.render.completed', subject: BeamParticle::class),
        )->run();

        $detail = $findings[0]->detail;

        $this->assertStringContainsString('2 of 3 registered event types', $detail);
        $this->assertStringContainsString('sprockets.provisioned (prefix [sprockets])', $detail);
        $this->assertStringContainsString('sprockets.render.completed (prefix [sprockets])', $detail);
        $this->assertStringNotContainsString('widgets.provisioned (prefix', $detail);
        $this->assertStringContainsString('Live resource keys: ', $detail);
        $this->assertStringContainsString('widgets', $detail);
    }
}
