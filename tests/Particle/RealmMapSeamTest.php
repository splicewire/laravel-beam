<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UnrealmedResourceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * Particle-manifest-repatriation ticket 02 — the seam that removes a host's reason to CONSTRUCT the
 * resource registry.
 *
 * ## What was actually missing, stated honestly
 *
 * `loadRealmMap()` has been additive since it was written, and beam binds the registry as a container
 * singleton, so `app(ParticleResourceRegistry::class)->loadRealmMap([...])` from a host's `boot()`
 * already worked. Nothing in the estate did it, nothing said it was allowed, and `~/Herd/splicewire-app`
 * instead re-bound the singleton — whose own comment admits the override "forces the realm-map seed to
 * be repeated here". The imperative half of this ticket is therefore a GUARANTEE and a set of tests,
 * not new code, and this file exists so it stops being an accident of implementation.
 *
 * The DECLARATIVE half was genuinely absent. `frame.realms` is frame's config file: a host adding one
 * key to it must publish and restate the whole map, which is the "restate rather than extend" defect
 * this map is named after. `beam.core.resources.realm_map` is a second, purely ADDITIVE source beam's
 * own binding reads after `frame.realms` — so a host declares only its delta and beam's seed is
 * untouched.
 *
 * ## Why the union is measurable at all
 *
 * `realmMap()` is new here too, and not for convenience: {@see UnrealmedResourceAudit}
 * read `config('frame.realms')` raw, on the explicitly stated premise that "the registry does not expose
 * the map it was seeded with, and this is its ONE seed". Adding a second seed falsifies that premise, and
 * the audit would have gone quietly blind to any resource realmed only through the new key — reporting it
 * as belonging to no realm at all. The accessor is what keeps the declared authority the authority.
 */
class RealmMapSeamTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('frame.realms', ['operator' => ['papers']]);
    }

    protected function aHostAddsItsOwnMembership(Application $app): void
    {
        $app['config']->set('beam.core.resources.realm_map', ['tenant' => ['papers', 'ledgers']]);
    }

    private function registry(): ParticleResourceRegistry
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(key: 'papers', backing: 'Acme\\Papers', label: 'Papers'));
        $registry->register(new ParticleResource(key: 'ledgers', backing: 'Acme\\Ledgers', label: 'Ledgers'));

        return $registry;
    }

    // ── the declarative half ────────────────────────────────────────────────────────────────────────

    #[DefineEnvironment('aHostAddsItsOwnMembership')]
    public function test_a_host_adds_realm_membership_through_config_without_rebinding_the_singleton(): void
    {
        $registry = $this->registry();

        $this->assertSame(['operator', 'tenant'], $registry->realmsFor('papers'));
        $this->assertSame(['tenant'], $registry->realmsFor('ledgers'));
    }

    #[DefineEnvironment('aHostAddsItsOwnMembership')]
    public function test_beams_own_frame_realms_seed_survives_the_host_addition(): void
    {
        // The acceptance item that matters most: the host's key is ADDITIVE, never a replacement. A host
        // that had to publish `frame.realms` to add one line would be restating beam's map to extend it.
        $this->assertSame(['papers'], $this->registry()->realmMap()['operator']);
    }

    public function test_a_host_that_declares_nothing_is_byte_for_byte_unchanged(): void
    {
        // 20 of the 21 `~/Herd` roots declare no realm map at all. The new key must be INERT for them.
        $this->assertSame(['operator' => ['papers']], $this->registry()->realmMap());
    }

    // ── the imperative half ─────────────────────────────────────────────────────────────────────────

    public function test_a_host_adds_membership_imperatively_on_the_shared_singleton(): void
    {
        $registry = $this->registry();
        $registry->loadRealmMap(['operator' => ['ledgers']]);

        $this->assertSame(['operator'], $registry->realmsFor('ledgers'));
        $this->assertSame(['papers', 'ledgers'], $registry->realmMap()['operator']);
    }

    public function test_seeding_after_registration_is_visible_because_membership_is_computed_on_read(): void
    {
        // Load ORDER must not decide membership — the same rule the event catalog learned the hard way.
        $registry = $this->registry();
        $this->assertSame([], $registry->realmsFor('ledgers'));

        $registry->loadRealmMap(['tenant' => ['ledgers']]);

        $this->assertSame(['tenant'], $registry->realmsFor('ledgers'));
    }

    #[DefineEnvironment('aHostAddsItsOwnMembership')]
    public function test_the_seam_is_idempotent_including_over_beams_own_seed(): void
    {
        $registry = $this->registry();
        $before = $registry->realmMap();

        $registry->loadRealmMap(config('frame.realms'));
        $registry->loadRealmMap(config('beam.core.resources.realm_map'));
        $registry->loadRealmMap(config('beam.core.resources.realm_map'));

        $this->assertSame($before, $registry->realmMap());
    }

    public function test_realms_named_at_registration_still_win_over_the_map(): void
    {
        // The `explicit` rung is rung 1 of two and this seam must not have quietly demoted it.
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(key: 'papers', backing: 'Acme\\Papers', label: 'Papers'), ['tenant']);
        $registry->loadRealmMap(['operator' => ['papers']]);

        $this->assertSame(['tenant'], $registry->realmsFor('papers'));
    }

    // ── the accessor the audit depends on ───────────────────────────────────────────────────────────

    #[DefineEnvironment('aHostAddsItsOwnMembership')]
    public function test_the_registry_exposes_the_union_of_every_seed_it_took(): void
    {
        $this->assertSame(
            ['operator' => ['papers'], 'tenant' => ['papers', 'ledgers']],
            $this->registry()->realmMap(),
        );
    }
}
