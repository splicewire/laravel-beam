<?php

namespace Splicewire\Beam\Tests\Events;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Events\BeamEvent;
use Splicewire\Beam\Events\BeamEventOccurred;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\ParticlePersistedEventRegistrar;
use Splicewire\Beam\Events\ResourceKeyOracle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;

/**
 * `{resource}.persisted` is delivered through the in-process signal only when the model resolves to one
 * writable resource. The catalog mark and write-time resolution must share that rule.
 */
class ParticlePersistedCatalogOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: PersistedTestParticle::class,
            frame: false,
        ));

        Bus::fake();
    }

    private function catalog(): EventTypeRegistry
    {
        $registry = new EventTypeRegistry(new ResourceKeyOracle($this->app));
        $registry->attach(new ParticlePersistedEventRegistrar(app(ParticleResourceRegistry::class)));

        return $registry;
    }

    public function test_an_annotated_event_carries_the_in_process_delivery_declaration(): void
    {
        $type = (new BeamEvent(
            name: 'widgets.changed',
            subject: PersistedTestParticle::class,
            deliveredInProcess: true,
        ))->toEventType();

        $this->assertTrue($type->deliveredInProcess);
    }

    public function test_the_catalog_marks_the_unique_model_resource_as_delivered_in_process(): void
    {
        $type = $this->catalog()->find('widgets.persisted');

        $this->assertNotNull($type);
        $this->assertTrue($type->deliveredInProcess);
    }

    public function test_a_persisted_write_dispatches_the_named_in_process_event(): void
    {
        Event::fake([BeamEventOccurred::class]);

        event(new BeamParticlePersisted(new PersistedTestParticle, ['title' => 'a widget']));

        Event::assertDispatched(BeamEventOccurred::class, fn (BeamEventOccurred $event) => $event->eventName === 'widgets.persisted'
                && $event->subject instanceof PersistedTestParticle
                && $event->payload === ['title' => 'a widget']
        );
        Bus::assertNotDispatched(DispatchWebhookJob::class);
    }

    public function test_an_ambiguous_model_is_not_marked_or_dispatched(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'other-widgets',
            backing: PersistedTestParticle::class,
            frame: false,
        ));

        $type = $this->catalog()->find('widgets.persisted');
        $this->assertNotNull($type);
        $this->assertFalse($type->deliveredInProcess);

        Event::fake([BeamEventOccurred::class]);
        event(new BeamParticlePersisted(new PersistedTestParticle, ['title' => 'a widget']));

        event(new BeamParticlePersisted(new UnregisteredTestParticle, ['title' => 'unregistered']));
        Event::assertNotDispatched(BeamEventOccurred::class);
    }
}

class PersistedTestParticle extends BeamParticle {}

class UnregisteredTestParticle extends BeamParticle {}
