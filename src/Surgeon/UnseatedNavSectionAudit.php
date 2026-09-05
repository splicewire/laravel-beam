<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\DataNav\NavContext;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * **A section a package DECLARED and nothing SEATS** — the other half of the nav declaration, checked.
 *
 * `#[ParticleResource(section: 'ops')]` says which section a resource attaches UNDER. It cannot bring
 * that section into being: a navigation collects declared resources as CHILDREN of a seat someone
 * registered. Until {@see NavSectionRegistry} existed only a host could register one, so a package could
 * declare children and could not declare the thing they hang from.
 *
 * Measured across the family on 2026-09-05: **9 packages declared 30 sections, and 11 of them — `ops`
 * ×6, `calendars` ×3, `authoring` ×2 — named a section no host seated.** Correctly declared, and
 * invisible. Nothing anywhere said so; there was no instrument that could, because every existing check
 * asks about the DECLARATION and this is a question about the pair. This is that instrument.
 *
 * ## ADVISORY, and permanently so
 *
 * Whether a section is seated is a fact about the HOST — which packages it composes, which realms it
 * ships, and whether it wrote its own navigation. The same declaration is a finding at one host and
 * correct at another, which is `rushing/laravel-doctor`'s textbook advisory case and this estate's
 * standing rule: *a check whose answer depends on the host must not throw*
 * (`docs/agents/traps/audits-and-findings.md`). An unseated section is very often the RIGHT outcome —
 * the host chose not to surface that package's admin rows — so this registers with `gate: false` and
 * emits {@see Finding::warn()}.
 *
 * ## ⚠️ What it can see, and the blind spot that matters most
 *
 * "Seated" here means **seated declaratively**, through {@see NavSectionRegistry}. It does NOT and
 * cannot mean "reachable in this host's navigation". A host that hand-authors its nav — as
 * `~/Herd/splicewire-app` does, in `app/Navigation/AppNavigation.php` — seats sections in a tree this
 * audit has no way to read: the tree is `Rushing\DataNav` vocabulary, beam does not depend on data-nav
 * (that is the whole reason {@see NavSection} is a plain value object here rather than an
 * `InvocableNavItem`), and the tree is built per request from a {@see NavContext} this
 * audit does not have.
 *
 * So a hand-seated section reports as unseated. That is a FALSE POSITIVE in the plain sense and it is
 * still the right trade: the alternative instrument — reading the host's nav tree — does not exist at
 * this tier and cannot be built here, and an audit that stayed silent rather than admit a blind spot
 * would be the estate's signature defect wearing a clean face. Every finding says so in its own text
 * rather than leaving the reader to infer it, and the census states the whole population so a low warn
 * count is never mistaken for coverage.
 *
 * ## Only PACKAGE declarations are warned on
 *
 * A host declaring a section for its own resource, and seating it in its own navigation, is the normal
 * arrangement and predates the registry entirely; warning on it would file the flagship's whole nav
 * every run and get the audit switched off. So a host-declared unseated section is COUNTED on the census
 * line and never warned.
 *
 * Provenance is read off the namespace of the class that declares the resource — {@see ParticleResource}
 * records no declaring package, and the family vendor prefixes (`Splicewire\`, `Rushing\`,
 * `Schemastud\`) are the one signal available in-process. ⚠️ That is a HEURISTIC, and it is the
 * conservative direction only for the split's warn side: a host that namespaces its own app code under a
 * family vendor would be read as a package. It is stated on the census line rather than buried here.
 *
 * It deliberately does NOT use {@see FamilyPackageSource}, which resolves family source by walking
 * `vendor/composer/installed.json`. That file is absent in a package's own testbench suite, so the split
 * would silently collapse to "everything is a host declaration" in exactly the harness where this audit
 * is tested — an instrument whose measurement disappears under its own tests.
 *
 * ## Blindness is separated from health, because both produce an empty list
 *
 * An empty finding list is what a healthy estate produces AND what a registry that never filled
 * produces. Two conditions are therefore reported on {@see Finding::inconclusive()} rather than as a
 * pass: **no resource registered at all** (nothing to have a section), and **no resource declaring a
 * section** (the population is empty, so the question was never asked). A registry holding resources
 * that all declare sections which are all seated is the only thing that earns a pass.
 */
class UnseatedNavSectionAudit implements DoctorAudit
{
    /** A package-declared `section:` that no registered {@see NavSection} seats at this host. */
    public const CHECK = 'nav.section.unseated';

    /** The census line: declared sections, seated, unseated, and how the population splits. */
    public const CHECK_CENSUS = 'nav.section';

    /** @var list<string> the namespace prefixes that mean "a family PACKAGE declared this". */
    protected const FAMILY_NAMESPACES = ['Splicewire\\', 'Rushing\\', 'Schemastud\\'];

    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected NavSectionRegistry $sections,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $registered = $this->resources->all();

        if ($registered === []) {
            return [Finding::inconclusive(self::CHECK_CENSUS, 'No particle resource is registered at this '
                .'host, so no resource can declare a `section:` and nothing was measured. This is not a '
                .'statement that every declared section is seated.')];
        }

        $declared = $this->declared();

        if ($declared === []) {
            return [Finding::inconclusive(self::CHECK_CENSUS, sprintf(
                'None of the %d registered particle resources declares a `section:`, so the population this '
                    .'audit measures is EMPTY and no seating question was asked. Nothing is wrong; nothing was '
                    .'checked either.',
                count($registered),
            ))];
        }

        $seated = $this->seatedKeys();
        $findings = [];

        foreach ($declared as $section => $rows) {
            if (in_array($section, $seated, true)) {
                continue;
            }

            if ($rows['packages'] === []) {
                // Host-declared and unseated — the normal arrangement. Counted below, never warned.
                continue;
            }

            $findings[] = Finding::warn(self::CHECK, sprintf(
                'The section `%s` is declared by %d resource(s) — %s — and NO registered `NavSection` seats '
                    .'it here, so those resources attach to nothing and never appear in a projected '
                    .'navigation. A `#[ParticleResource(section:)]` says what a resource sits UNDER; it cannot '
                    .'create the section. The seat is a separate declaration: '
                    .'`$app->make(%s::class)->register(new NavSection(key: \'%s\', realm: …, label: …, icon: …, '
                    .'href: …, order: …, entitlement: null, permission: null), by: \'<package>\')` from the '
                    .'declaring package\'s own provider, `bound()`-guarded. ⚠️ ADVISORY, and there are three '
                    .'ways this row is correct as it stands: this host may seat `%s` in a HAND-AUTHORED '
                    .'navigation, which is `Rushing\\DataNav` vocabulary this audit structurally cannot read; '
                    .'the host may have chosen not to surface this package\'s rows at all, in which case the '
                    .'silence is the decision working; or the resources may belong to no realm here, in which '
                    .'case a seat would render empty and be dropped anyway. Seat it only if you know which of '
                    .'those is false.',
                $section,
                count($rows['keys']),
                implode(', ', $rows['keys']),
                NavSectionRegistry::class,
                $section,
                $section,
            ));
        }

        $findings[] = $this->census($registered, $declared, $seated);

        return $findings;
    }

    /**
     * Declared section => the resource keys naming it, split by whether a family PACKAGE or the host
     * declared each.
     *
     * Section keys sorted, so two runs over the same host read the same way.
     *
     * @return array<string, array{keys: list<string>, packages: list<string>, host: list<string>}>
     */
    public function declared(): array
    {
        $found = [];

        foreach ($this->resources->all() as $resource) {
            $section = $resource->section;

            if (! is_string($section) || $section === '') {
                continue;
            }

            $found[$section] ??= ['keys' => [], 'packages' => [], 'host' => []];
            $found[$section]['keys'][] = $resource->key;
            $found[$section][$this->isPackageDeclared($resource) ? 'packages' : 'host'][] = $resource->key;
        }

        ksort($found);

        return $found;
    }

    /**
     * Declared section => resource keys, for the sections a package declared and nothing seats — the
     * warn population, exposed so a caller can count it without re-deriving the split.
     *
     * @return array<string, list<string>>
     */
    public function unseated(): array
    {
        $seated = $this->seatedKeys();
        $out = [];

        foreach ($this->declared() as $section => $rows) {
            if (in_array($section, $seated, true) || $rows['packages'] === []) {
                continue;
            }

            $out[$section] = $rows['keys'];
        }

        return $out;
    }

    /**
     * Every section key some registered {@see NavSection} seats, in ANY realm.
     *
     * Realm-blind on purpose. A seat is declared per realm and a package declares for every realm it
     * plausibly owns, because realm membership is the host's `config/frame.realms` list and the package
     * cannot know it. Intersecting seat realm against resource realm here would re-derive that
     * membership from a second place and report a package that did the right thing — declared for both
     * `operator` and `tenant` — as unseated in whichever one turned out empty. The question this audit
     * asks is whether the section was seated AT ALL.
     *
     * @return list<string>
     */
    public function seatedKeys(): array
    {
        $keys = array_map(
            static fn (NavSection $section): string => $section->key,
            $this->sections->all(),
        );

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<ParticleResource>  $registered
     * @param  array<string, array{keys: list<string>, packages: list<string>, host: list<string>}>  $declared
     * @param  list<string>  $seated
     */
    protected function census(array $registered, array $declared, array $seated): Finding
    {
        $unseatedPackage = array_keys($this->unseated());

        $unseatedHost = array_keys(array_filter(
            $declared,
            fn (array $rows, string $section): bool => ! in_array($section, $seated, true)
                && $rows['packages'] === [],
            ARRAY_FILTER_USE_BOTH,
        ));

        $message = sprintf(
            '%d of %d registered resources declare a `section:`, naming %d distinct section(s); %d seated by a '
                .'registered NavSection, %d unseated and package-declared (warned above), %d unseated and '
                .'host-declared (not warned — a host seats its own sections in its own navigation). '
                .'Unseated package sections: %s. Unseated host sections: %s. NOT covered: a section this host '
                .'seats in a HAND-AUTHORED `Rushing\\DataNav` tree, which beam cannot read and which this '
                .'audit therefore reports as unseated. Provenance is the declaring class\'s namespace '
                .'(Splicewire\\, Rushing\\, Schemastud\\ = package), a heuristic — host code namespaced under '
                .'a family vendor reads as a package here.',
            count(array_merge(...array_map(fn (array $rows): array => $rows['keys'], array_values($declared)))),
            count($registered),
            count($declared),
            count($declared) - count($unseatedPackage) - count($unseatedHost),
            count($unseatedPackage),
            count($unseatedHost),
            $unseatedPackage === [] ? 'none' : implode(', ', $unseatedPackage),
            $unseatedHost === [] ? 'none' : implode(', ', $unseatedHost),
        );

        return $unseatedPackage === []
            ? Finding::pass(self::CHECK_CENSUS, $message)
            : Finding::warn(self::CHECK_CENSUS, $message);
    }

    /**
     * Whether a family package, rather than the host, declares this resource — read off the namespace of
     * its Data class, falling back to the backing when the declaration ships no Data class.
     *
     * A backing given as an object (a constructed {@see ResourceBacking})
     * is read through `get_class`, so a source-backed registration is not silently attributed to the
     * host for want of a string.
     */
    protected function isPackageDeclared(ParticleResource $resource): bool
    {
        $class = is_string($resource->data) && $resource->data !== ''
            ? $resource->data
            : (is_string($resource->backing) ? $resource->backing : $resource->backing::class);

        foreach (self::FAMILY_NAMESPACES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
