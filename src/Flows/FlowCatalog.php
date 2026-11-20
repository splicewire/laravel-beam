<?php

namespace Splicewire\Beam\Flows;

use LogicException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\Registrant;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\ScopesRegistrant;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Realm\RealmOverlayRegistry;

/**
 * The `beam.flows` catalog: every flow any installed package declares, keyed by its flow key
 * (browser-journeys 01 and 02). A flow is a named, host-visible capability path
 * (`account.security.passkeys`, `auth.register`, `teams.team-of-one`) that a browser test proves, a
 * `BEAM.md` Deviation may switch off, and the doctor reasons about.
 *
 * A SINGLETON, bound in {@see BeamServiceProvider}, so each package registering from
 * its own provider mutates the one instance the doctor, the helper, the docs generator and the
 * listing read. Inert by default: a host with no flow-shipping package has an empty, legal catalog
 * (`PopulationRequirement::Optional`).
 *
 * Modeled on {@see RealmOverlayRegistry}: it holds a {@see BasicRegistry} and
 * delegates the {@see Registry} contract to it. `OnKeyDuplicate::Admit` means two installed packages
 * may declare the same key; both are retained (never thrown at boot, never superseded), and a read of
 * that exact key is honestly ambiguous — the doctor names both registrants, the browser-test helper
 * refuses the ambiguous key inside the test.
 */
#[IsRegistry(
    root: 'beam.flows',
    entryType: FlowDeclaration::class,
    onKeyDuplicate: OnKeyDuplicate::Admit,
    populationRequirement: PopulationRequirement::Optional,
    description: 'Flow declarations keyed by flow key (surface[.flow[.subflow]]). Each package ships one static DeclaresFlows class, declared by its provider under its registrant. Admit: two packages may declare one key; both are retained and the doctor names both registrants. A host with no flow-shipping package is a legal, empty catalog.',
    order: 20,
)]
/**
 * @implements Registry<FlowDeclaration>
 */
final class FlowCatalog implements Gated, RecordsRegistrants, Registry
{
    /** @var BasicRegistry<FlowDeclaration> flow key => its declaration(s). */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register every flow a package's {@see DeclaresFlows} class ships, each under its own key.
     *
     * The declaring package is the registrant `$by`: an explicit argument, or the registrant of the
     * {@see Registrant::scoped()} block in force (a package provider's {@see ScopesRegistrant}
     * scope). `declare()` REFUSES when neither is present — a package-wiring bug, caught by the package's
     * own suite, never a host-dependent answer.
     *
     * @param  class-string<DeclaresFlows>  $class
     *
     * @throws LogicException when no registrant is in force
     */
    public function declare(string $class, ?string $by = null): static
    {
        $registrant = $by ?? Registrant::current();

        if ($registrant === null) {
            throw new LogicException(
                "FlowCatalog::declare({$class}) needs a registrant: call it inside a ScopesRegistrant ".
                'scope, or pass an explicit by:.'
            );
        }

        foreach ($class::flows() as $flow) {
            $this->register($flow->key, $flow, $registrant);
        }

        return $this;
    }

    /**
     * The declaration(s) at EXACTLY `$key`. Reads the subtree via `matches()` and keeps only the exact
     * key, so a surface that also has children (`account` with `account.security` under it) is never
     * reported as ambiguous. Under `Admit`, more than one is a genuine duplicate the doctor reports.
     *
     * @return list<FlowDeclaration>
     */
    public function declarations(string $key): array
    {
        return array_values(array_filter(
            $this->store->matches($key),
            static fn (FlowDeclaration $flow): bool => $flow->key === $key,
        ));
    }

    /* ---------------- Registry contract (delegated to the store) ---------------- */

    public function register(
        RegistryKey|string $key,
        mixed $entry,
        ?string $by = null,
        ?string $ability = null,
    ): static {
        $this->store->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return list<FlowDeclaration> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function registrantOf(RegistryKey|string $key): ?string
    {
        // A malformed key is a miss, never a throw (the RecordsRegistrants contract): guard the
        // unparseable string before the store, exactly as RealmOverlayRegistry does.
        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->store->registrantOf($key);
    }

    /** @return list<RegistryKey> */
    public function keysBy(string $registrant): array
    {
        return $this->store->keysBy($registrant);
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->store->authorizeWith($authorizer);

        return $this;
    }
}
