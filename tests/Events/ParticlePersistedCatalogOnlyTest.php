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
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;

/**
 * `{resource}.persisted` is delivered to Beam listeners only when its model maps to one writable
 * resource; Beam does not deliver it to webhooks yet. Its published description explains both facts.
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

    public function test_published_description_discloses_webhook_non_delivery_for_marked_and_unmarked_entries(): void
    {
        Hook::create([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['widgets.persisted'],
        ]);

        $marked = $this->catalog()->find('widgets.persisted');
        $this->assertNotNull($marked);
        $this->assertTrue($marked->deliveredInProcess);
        $this->assertStringContainsString('Delivered in process only: Beam does not deliver this event to webhooks yet', $marked->description);

        event(new BeamParticlePersisted(new PersistedTestParticle, ['title' => 'marked widget']));
        Bus::assertNotDispatched(DispatchWebhookJob::class);

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'other-widgets',
            backing: PersistedTestParticle::class,
            frame: false,
        ));
        Hook::create([
            'endpoint' => 'https://receiver.test/other-hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['other-widgets.persisted'],
        ]);

        $unmarked = $this->catalog()->find('widgets.persisted');
        $this->assertNotNull($unmarked);
        $this->assertFalse($unmarked->deliveredInProcess);
        $this->assertStringContainsString('Catalog-only: Beam does not deliver this event to webhooks yet', $unmarked->description);

        event(new BeamParticlePersisted(new PersistedTestParticle, ['title' => 'ambiguous widget']));
        Bus::assertNotDispatched(DispatchWebhookJob::class);
    }
}

class PersistedTestParticle extends BeamParticle {}

class UnregisteredTestParticle extends BeamParticle {}
