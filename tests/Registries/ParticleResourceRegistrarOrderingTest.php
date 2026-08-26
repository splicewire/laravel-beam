<?php

namespace Splicewire\Beam\Tests\Registries;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Rushing\Popcorn\Registries\Registrars\AttributeRegistrar;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperModel;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperResource;
use Splicewire\Beam\Tests\TestCase;

/**
 * The estate's FIRST live registrar attach, asserted against a real provider graph
 * (registry-kernel ticket 53, paying ticket 19 D3's clause and ticket 07's four-times-relayed criterion
 * *"test each registrar against a live estate registry"*).
 *
 * `php-popcorn`'s own `RegistrarTest:134` already asserts the claim at unit level, with both directions.
 * What was owed and unpayable until now is the same claim where **Laravel's boot order arbitrates**:
 * beam attaches an `AttributeRegistrar` in `BeamServiceProvider::boot()`, a consumer provider registered
 * after it hand-registers the same key in its own `boot()`, and the hand-registered entry is the one
 * that resolves — by `OnDuplicate::Supersede` alone, with no tier, no branch and no precedence rule.
 *
 * The criterion was unsatisfiable for as long as it was because `Registrar::fill()` needs a registry
 * that IMPLEMENTS `Registry`, and no registrar-fed estate registry did (ticket 21 D1: 53 declaring,
 * zero conforming; ticket 37 migrated four exemplars and none of them used a registrar).
 */
class ParticleResourceRegistrarOrderingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Appended AFTER beam's own provider, so Laravel boots it second — which is the whole point.
        return [...parent::getPackageProviders($app), ConsumerResourceServiceProvider::class];
    }

    protected function scanTheRegistrarFixtures(Application $app): void
    {
        $app['config']->set('beam.core.resources.discover_paths', [__DIR__.'/../Fixtures/Registrar']);
    }

    #[DefineEnvironment('scanTheRegistrarFixtures')]
    public function test_the_registrar_is_attached_by_the_owners_own_boot(): void
    {
        $registrars = $this->app->make(ParticleResourceRegistry::class)->registrars();

        $this->assertCount(1, $registrars);
        $this->assertInstanceOf(AttributeRegistrar::class, $registrars[0]);
        $this->assertStringStartsWith('#[ParticleResource] under ', $registrars[0]->source());
    }

    #[DefineEnvironment('scanTheRegistrarFixtures')]
    public function test_the_registrar_actually_filled_the_registry(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);

        $this->assertTrue($registry->has('scanned-papers'));
        $this->assertSame(ScannedPaperModel::class, $registry->get('scanned-papers')->backing);
    }

    /**
     * The load-bearing one: ticket 19 D3's assertion, against a real provider graph.
     *
     * `perPage` is the discriminator — 7 from the scanned declaration, 99 from the consumer provider.
     */
    #[DefineEnvironment('scanTheRegistrarFixtures')]
    public function test_a_consumer_providers_hand_registration_wins_over_the_registrar(): void
    {
        $this->assertSame(99, $this->app->make(ParticleResourceRegistry::class)->get('scanned-papers')->perPage);
    }

    /**
     * ...and the loser is RECORDED, with the registrant the registrar wrote — the scanned class's own
     * FQCN, per ticket 07 D13. That is what makes a wrong-order overwrite visible rather than silent,
     * which is ticket 19's third acceptance item (unmet in general; answerable here).
     */
    #[DefineEnvironment('scanTheRegistrarFixtures')]
    public function test_the_registrars_entry_is_recorded_as_superseded_by_the_consumer(): void
    {
        $displaced = $this->app->make(ParticleResourceRegistry::class)->superseded('scanned-papers');

        $this->assertCount(1, $displaced);
        $this->assertSame(7, $displaced[0]->entry->perPage);
        $this->assertSame(ScannedPaperResource::class, $displaced[0]->by);
    }

    /**
     * With no consumer in the graph the registrar's own entry stands — the control, without which the
     * test above would still pass if the registrar had never run at all.
     */
    #[DefineEnvironment('scanTheRegistrarFixtures')]
    public function test_without_the_consumer_the_scanned_declaration_is_what_resolves(): void
    {
        $registry = new ParticleResourceRegistry;

        $registry->attach(new AttributeRegistrar(
            paths: [__DIR__.'/../Fixtures/Registrar'],
            attribute: \Splicewire\Beam\Particle\Attributes\ParticleResource::class,
            project: fn (string $class) => AttributedParticleDiscovery::resourceFromAttribute($class),
            instanceof: false,
        ));

        $this->assertSame(7, $registry->get('scanned-papers')->perPage);
        $this->assertSame([], $registry->superseded('scanned-papers'));
    }
}

/**
 * A consumer package's provider: boots AFTER beam and hand-registers into beam's registry, which is the
 * ordinary way a capability package contributes a resource.
 */
class ConsumerResourceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(
            new ParticleResource(
                key: 'scanned-papers',
                backing: ScannedPaperModel::class,
                perPage: 99,
            ),
            by: self::class,
        );
    }
}
