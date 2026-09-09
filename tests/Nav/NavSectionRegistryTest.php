<?php

namespace Splicewire\Beam\Tests\Nav;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Nav\NavSeatLock;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The package-facing NAV SEAT seam.
 *
 * `#[ParticleResource(section:)]` declares which section a resource sits UNDER; nothing could SEAT that
 * section, so 11 of the family's 30 package-declared sections (`ops` ×6, `calendars` ×3, `authoring` ×2)
 * named a section no host hand-authored and were correctly declared and invisible. {@see NavSection} is
 * the declaration; {@see NavSectionRegistry} is where it composes.
 *
 * Four claims, one per behaviour that would be silently wrong if the shape were a stub:
 *
 *   1. several packages contribute into ONE realm additively (`ComposeMany` + `Admit`);
 *   2. the read imposes a TOTAL order that boot order cannot perturb;
 *   3. a seat for a realm the host does not ship is a SILENT NO-OP — not an error, not a new realm;
 *   4. an ungated seat and a declared-but-empty gate are DISTINGUISHABLE — "I chose not to gate" and
 *      "I forgot" must not be spelled the same (which is also why the gate parameters have no default).
 */
class NavSectionRegistryTest extends TestCase
{
    private function registry(): NavSectionRegistry
    {
        // A projector reads the singleton the provider bound, so mutate THAT instance.
        return $this->app->make(NavSectionRegistry::class);
    }

    private function seat(
        string $realm,
        string $key,
        int $order,
        ?array $entitlement = null,
        ?string $permission = null,
        array $static = [],
    ): NavSection {
        return new NavSection(
            key: $key,
            realm: $realm,
            label: ucfirst($key),
            icon: 'Circle',
            href: '/'.$key,
            order: $order,
            entitlement: $entitlement,
            permission: $permission,
            static: $static,
        );
    }

    /** @return list<string> */
    private function keysOf(array $sections): array
    {
        return array_map(static fn (NavSection $s): string => $s->key, $sections);
    }

    public function test_it_ships_empty_and_therefore_inert(): void
    {
        $this->assertSame([], $this->registry()->for('tenant'));
        $this->assertSame([], $this->registry()->all());
        $this->assertSame([], $this->registry()->targetedRealmKeys());
        $this->assertFalse($this->registry()->has('tenant'));
    }

    public function test_the_provider_binds_it_as_a_shared_singleton(): void
    {
        // Not decoration: if it resolved fresh, a package registering from its own provider would write
        // into an instance the projector never sees — the exact defect that made two nav sections
        // silently empty in the flagship (AppNavigation's own docblock).
        $this->registry()->register($this->seat('tenant', 'calendars', order: 10));

        $this->assertSame(['calendars'], $this->keysOf($this->app->make(NavSectionRegistry::class)->for('tenant')));
    }

    public function test_several_packages_compose_additively_into_one_realm(): void
    {
        // Three registrars, one region. `OnKeyDuplicate::Admit` is what makes the second and third joins
        // rather than replacements; a Supersede registry would leave one seat here.
        $this->registry()
            ->register($this->seat('tenant', 'calendars', order: 20), by: 'splicewire/laravel-beam-calendars')
            ->register($this->seat('tenant', 'ops', order: 30), by: 'splicewire/laravel-beam-workflows')
            ->register($this->seat('tenant', 'authoring', order: 10), by: 'splicewire/laravel-beam-notifications');

        $this->assertCount(3, $this->registry()->for('tenant'));
        $this->assertSame(['authoring', 'calendars', 'ops'], $this->keysOf($this->registry()->for('tenant')));
    }

    public function test_a_realm_keeps_its_own_seats_and_never_borrows_anothers(): void
    {
        $this->registry()
            ->register($this->seat('tenant', 'calendars', order: 10))
            ->register($this->seat('operator', 'ops', order: 10));

        $this->assertSame(['calendars'], $this->keysOf($this->registry()->for('tenant')));
        $this->assertSame(['ops'], $this->keysOf($this->registry()->for('operator')));
        // `all()` is realm key ASCENDING, then the in-realm order — `operator` before `tenant`.
        $this->assertSame(['ops', 'calendars'], $this->keysOf($this->registry()->all()));
    }

    public function test_the_projected_order_is_declared_order_and_not_registration_order(): void
    {
        // Registered deliberately backwards. A registry that returned `matches()` straight through —
        // which is what RealmOverlayRegistry does, correctly, because overlays FOLD — would answer
        // `['zulu', 'mike', 'alpha']` here.
        $this->registry()
            ->register($this->seat('tenant', 'zulu', order: 30))
            ->register($this->seat('tenant', 'mike', order: 20))
            ->register($this->seat('tenant', 'alpha', order: 10));

        $this->assertSame(['alpha', 'mike', 'zulu'], $this->keysOf($this->registry()->for('tenant')));

        // And the raw contract read still reports registration order, so the two are not conflated.
        $this->assertSame(['zulu', 'mike', 'alpha'], $this->keysOf($this->registry()->matches('tenant')));
    }

    public function test_an_order_tie_is_broken_by_key_so_boot_order_cannot_perturb_the_nav(): void
    {
        // Two packages that both said `order: 10` must project identically whichever provider boots
        // first. Without the `key` tie-break this passes in one boot order and fails in the other —
        // the class of bug a suite that only ever registers in one order cannot see.
        $forward = $this->app->make(NavSectionRegistry::class);
        $forward
            ->register($this->seat('tenant', 'bravo', order: 10))
            ->register($this->seat('tenant', 'alpha', order: 10));

        $reverse = new NavSectionRegistry;
        $reverse
            ->register($this->seat('tenant', 'alpha', order: 10))
            ->register($this->seat('tenant', 'bravo', order: 10));

        $this->assertSame(['alpha', 'bravo'], $this->keysOf($forward->for('tenant')));
        $this->assertSame($this->keysOf($forward->for('tenant')), $this->keysOf($reverse->for('tenant')));
    }

    public function test_a_negative_order_seats_a_package_section_ahead_of_the_hosts_own(): void
    {
        // `order` is a signed int on purpose — a package may ask to lead. It is still only a request:
        // ordering and override remain the host's (issue 142's closing rule).
        $this->registry()
            ->register($this->seat('tenant', 'studio', order: 0))
            ->register($this->seat('tenant', 'alerts', order: -10));

        $this->assertSame(['alerts', 'studio'], $this->keysOf($this->registry()->for('tenant')));
    }

    public function test_a_seat_for_a_realm_the_host_does_not_ship_is_a_silent_no_op(): void
    {
        $realms = $this->app->make(RealmRegistry::class);
        $this->assertFalse($realms->has('atlantis'), 'precondition: the host does not ship an `atlantis` realm');

        // Accepted without throwing — a package must not fatal a host boot by naming a region that host
        // happens not to have installed.
        $this->registry()
            ->register($this->seat('tenant', 'calendars', order: 10))
            ->register($this->seat('atlantis', 'ops', order: 10));

        // …and structurally unreachable: a projector iterates the realms the host HAS and asks about
        // each, so the seat is stored and never read. It cannot conjure a region into being.
        $projected = [];

        foreach (array_keys($realms->all()) as $realmKey) {
            foreach ($this->registry()->for($realmKey) as $section) {
                $projected[] = $realmKey.'/'.$section->key;
            }
        }

        $this->assertSame(['tenant/calendars'], $projected);
        $this->assertNotContains('atlantis', array_keys($realms->all()));

        // The seat IS on file — this is a no-op at projection, not a dropped registration, and the
        // difference is what a doctor/diagnostic needs to be able to see.
        $this->assertContains('atlantis', $this->registry()->targetedRealmKeys());
        $this->assertSame([], $this->registry()->for('narnia'), 'a realm nobody seated reads the same as one nobody has');
    }

    public function test_an_ungated_seat_is_distinguishable_from_a_declared_empty_gate(): void
    {
        $ungated = $this->seat('tenant', 'knowledge', order: 10, entitlement: null);
        $emptyGate = $this->seat('tenant', 'vault', order: 20, entitlement: []);
        $realGate = $this->seat('tenant', 'studio', order: 30, entitlement: ['composition.content'], permission: 'studio.view');

        // `null` means UNGATED — always visible. `[]` means an any-of list that was declared and admits
        // nobody. Neither is normalised into the other, and neither is normalised into "absent".
        $this->assertFalse($ungated->isEntitlementGated());
        $this->assertTrue($emptyGate->isEntitlementGated());
        $this->assertTrue($realGate->isEntitlementGated());

        $this->assertFalse($ungated->isGated());
        $this->assertTrue($emptyGate->isGated());

        // The projected gate bag drops undeclared axes and KEEPS a declared-empty one — mirroring the
        // flagship's own `array_filter(..., $value !== null)`. An `array_filter()` with no callback
        // would eat the `[]` and make the two spellings identical downstream.
        $this->assertSame([], $ungated->gate());
        $this->assertSame(['entitlement' => []], $emptyGate->gate());
        $this->assertSame(
            ['entitlement' => ['composition.content'], 'permission' => 'studio.view'],
            $realGate->gate(),
        );

        // Survives the registry round-trip rather than only holding on a freshly-built object.
        $this->registry()->register($ungated)->register($emptyGate);
        [$read] = array_values(array_filter(
            $this->registry()->for('tenant'),
            static fn (NavSection $s): bool => $s->key === 'vault',
        ));
        $this->assertSame(['entitlement' => []], $read->gate());
    }

    public function test_the_gate_parameters_have_no_defaults_so_omission_is_not_a_decision(): void
    {
        // The static half of the claim above: `entitlement: null` must be WRITTEN. A `= null` default
        // would spell "I chose not to gate" and "I forgot" identically, and no runtime assertion can
        // tell those apart after the fact — so the constructor signature is the assertion.
        $parameters = (new \ReflectionMethod(NavSection::class, '__construct'))->getParameters();
        $byName = [];

        foreach ($parameters as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        foreach (['key', 'realm', 'label', 'icon', 'href', 'order', 'entitlement', 'permission'] as $required) {
            $this->assertFalse(
                $byName[$required]->isDefaultValueAvailable(),
                "NavSection::\${$required} must be declared at every site — a default makes an omission "
                    .'indistinguishable from a decision.',
            );
        }

        // `static` is the one exception and is not gate vocabulary: no static children is the ordinary
        // shape, and an empty list says the same thing as saying nothing.
        $this->assertTrue($byName['static']->isDefaultValueAvailable());
    }

    public function test_a_seat_can_express_everything_the_flagships_own_section_helper_expresses(): void
    {
        // Modelled on `~/Herd/splicewire-app/app/Navigation/AppNavigation.php`'s private `section()`
        // (realm, key, label, icon, href, entitlement any-of, permission, static rows). If a declared
        // seat could not say all of it, the flagship could not be re-expressed as declarations and the
        // seam would only ever serve half the estate.
        $seat = new NavSection(
            key: 'platform',
            realm: 'operator',
            label: 'Platform',
            icon: 'Building',
            href: '/operator/tenants',
            order: 10,
            entitlement: null,
            permission: null,
            static: [
                ['title' => 'Dashboard', 'href' => '/operator/dashboard', 'icon' => 'LayoutDashboard', 'routeName' => 'dashboard.section', 'navOrder' => 0],
                ['title' => 'Connectors', 'href' => '/operator/connectors', 'icon' => 'Cable', 'routeName' => 'connectors.section'],
            ],
        );

        $this->assertSame('platform', $seat->key);
        $this->assertSame('operator', $seat->realm);
        $this->assertSame('/operator/tenants', $seat->href);
        $this->assertCount(2, $seat->static);
        $this->assertSame('Dashboard', $seat->static[0]['title']);
        $this->assertSame([], $seat->gate());
    }

    public function test_it_carries_no_data_nav_type(): void
    {
        // The load-bearing constraint on WHERE this lives. The three packages that need to seat a section
        // (`laravel-beam-calendars`, `-notifications`, `-workflows`) require ONLY `splicewire/laravel-beam`
        // — no `laravel-beam-ux`, no `rushing/laravel-data-nav` — so the declaration site must be beam
        // core AND must not drag data-nav in behind it. A beam-UX projector turns a seat into an
        // `InvocableNavItem`; beam itself never names one.
        foreach ([NavSection::class, NavSectionRegistry::class, NavSeatLock::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            // Imports, not prose — both docblocks NAME data-nav to explain why they avoid it, and a
            // substring probe would read that explanation as the violation it describes.
            $this->assertSame(
                0,
                preg_match('/^\s*use\s+Rushing\\\\DataNav/m', $source),
                "{$class} must not import a data-nav type",
            );
        }

        // The signature is the other half: nothing a package passes may smuggle a nav-library type
        // across the seam.
        //
        // ⚠️ This used to read "every field is a scalar or an array" and assert `isBuiltin()`. That was
        // a PROXY for the real invariant, and it was over-broad: it banned a beam-owned value object
        // (`NavSeatLock`, the soft-gate declaration) which cannot smuggle anything, while proving
        // nothing about a class it did admit. It now checks the invariant itself — a class-typed field
        // is legal only if that class is in beam's own `Nav` namespace AND passes the same
        // data-nav-free import probe above, recursively. Strictly stronger than the proxy: a
        // `Rushing\DataNav\NavLocked` parameter fails on both clauses where `isBuiltin()` caught it on
        // one, and any future value object is verified rather than merely forbidden.
        foreach ((new \ReflectionMethod(NavSection::class, '__construct'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            $this->assertInstanceOf(\ReflectionNamedType::class, $type, "\${$name} must be simply typed");

            if ($type->isBuiltin()) {
                continue;
            }

            $this->assertStringStartsWith(
                'Splicewire\Beam\Nav\\',
                $type->getName(),
                "\${$name} may only be typed as a beam-owned Nav value object, never a nav-library type",
            );

            $this->assertSame(
                0,
                preg_match(
                    '/^\s*use\s+Rushing\\\\DataNav/m',
                    file_get_contents((new \ReflectionClass($type->getName()))->getFileName()),
                ),
                "\${$name}'s type must not import a data-nav type either",
            );
        }

        // ⚠️ This used to also assert beam's composer.json does NOT require `rushing/laravel-data-nav`.
        // That assertion was removed 2026-09-05 when beam took the dependency deliberately, and the
        // reasoning behind it was simply wrong: beam already hard-requires NINE rushing/* packages
        // (data-filters, doctor, permission-cascade, popcorn, versioning, codegen, …), so
        // splicewire -> rushing is the SANCTIONED direction (ADR-0092, paid -> open) and the
        // package-topology `noRequire` policy in that same manifest forbids only schemastud->splicewire,
        // beam->satellite and beam->tower. Nothing ever forbade this edge.
        //
        // What the dependency bought: `UnseatedNavSectionAudit` can read a host's HAND-AUTHORED
        // navigation, not just the declarative registry. Without it the audit reported six unseated
        // sections at the flagship that are all seated by hand — six of six false positives.
        //
        // Everything ABOVE this comment still holds and is the part that mattered: `NavSection` stays
        // a plain value object with builtin-typed fields and no data-nav import. That is not about
        // what beam may depend on — it is that a SEAT DECLARATION is not a nav node. The projector at
        // the beam-ux tier owns the translation, and keeping the two apart is what lets a package
        // declare a seat without knowing how it will be rendered.
    }

    public function test_the_registry_declares_itself(): void
    {
        $declaration = IsRegistry::of(NavSectionRegistry::class);

        $this->assertNotNull($declaration);
        $this->assertSame('beam.nav.sections', $declaration->root);
        $this->assertSame(NavSection::class, $declaration->entryType);

        // Optional is what makes "no package seated anything" a legal state rather than a boot failure —
        // the inert default this seam depends on. Declared rather than inherited, so it is a claim.
        $this->assertSame(PopulationRequirement::Optional, $declaration->populationRequirement);
    }
}
