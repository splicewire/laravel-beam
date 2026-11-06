<?php

namespace Splicewire\Beam\Tests\Events;

use Illuminate\Support\Facades\Bus;
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
 * `{resource}.persisted` is catalogued and not delivered. The two halves are pinned together: a
 * subscribed hook receives nothing when a record is written, and the description `GET /hooks/events`
 * publishes says so. Wiring delivery turns the first half red, and the description must change with it.
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
            backing: BeamParticle::class,
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

    public function test_the_published_description_says_the_event_is_catalog_only(): void
    {
        $type = $this->catalog()->find('widgets.persisted');

        $this->assertNotNull($type);
        $this->assertSame('A widgets '.ParticlePersistedEventRegistrar::DESCRIPTION, $type->description);
        $this->assertStringContainsString('Catalog-only', (string) $type->description);
    }

    public function test_a_write_announcement_reaches_no_subscribed_hook(): void
    {
        Hook::create([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['widgets.persisted'],
        ]);

        event(new BeamParticlePersisted(new BeamParticle, ['title' => 'a widget']));

        Bus::assertNotDispatched(DispatchWebhookJob::class);
    }
}
