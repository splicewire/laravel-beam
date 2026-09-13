<?php

namespace Splicewire\Beam\Particle\Backing\Merge;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Particle\Backing\CompositeBacking;

/**
 * The plug board of the merge socket: {@see MergeStrategy} implementations by handle, so a
 * {@see CompositeBacking} names the strategy it wants
 * (`mergeHandle(): 'ordered'`) and beam never has to know which package supplied it.
 *
 * It is the same shape the grounding kernel's fusion socket has one tier over — an interface owned by
 * the low package, a default plug bound by that package, and a handle a higher package may claim
 * instead — expressed here as a popcorn registry rather than a hand-rolled array, because that is what
 * makes it visible to `popcorn:registries`, to the index, and to the conformance gate.
 *
 * ## The root is `beam.particle.merge`, not `particle.merge`
 *
 * Composite-backing's charter wrote the socket as `particle.merge.<handle>`, and this deviates on the
 * segment before it. Every registry beam owns roots under `beam.` (`beam.particle.resources`,
 * `beam.particle.operations`, `beam.particle.relatives`, `beam.particle.resource-contributions` — the
 * four this one sits beside), the index routes a key to its registry by ROOT PREFIX, and roots must be
 * unique estate-wide. An unprefixed `particle.merge` would have beam claim a top-level `particle`
 * neighbourhood no package owns and that a host or satellite could reasonably want — a collision the
 * index records rather than refuses, which is exactly the class of boot-order accident
 * `particle-operation-surface`'s ⚠️ warns about. The HANDLE a composite declares is unchanged and bare
 * (`ordered`); the root is stamped on by {@see BasicRegistry}, so nothing outside this file spells it.
 *
 * ## Entries are instances; beam's own default is seeded in the CONSTRUCTOR
 *
 * A strategy is stateless and shared, so the registry holds the object rather than a class-string. A
 * package shipping ranked fusion (ticket 05) registers its own instance under its own handle from ITS
 * OWN `boot()` — the register-DOWN direction every other registry in the estate uses, and the only
 * direction that stays right when beam does not know the contributor exists.
 *
 * ⚠️ Beam's own `ordered` plug is seeded HERE rather than from `BeamServiceProvider::packageBooted()`,
 * and that is the one deliberate departure from the sibling registries. The register-down rule governs
 * what OTHER packages contribute; a socket's own default is not a contribution, it is part of the socket,
 * and making it depend on a provider boot makes the default absent in exactly the environments the socket
 * is first exercised in. Measured 2026-09-12: `splicewire/tower`'s testbench does not register
 * `BeamServiceProvider` (it reaches beam transitively, and testbench does not auto-discover — the SEVENTH
 * instance of the trap tower's own `TestCase` documents six times), so the first tower test through a
 * composite failed with `RegistryMiss: No entry registered under beam.particle.merge.ordered` against a
 * registry that had been bound, constructed and read successfully. A host may still override the handle:
 * a later `register('ordered', …)` supersedes, and the displaced default stays readable through
 * `superseded()`.
 *
 * @implements Registry<MergeStrategy>
 */
#[IsRegistry(
    root: 'beam.particle.merge',
    entryType: MergeStrategy::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'merge strategies for a CompositeBacking, by handle. Beam registers `ordered` (a k-way merge on a declared sort key, composite cursor = an encoded {arm => armCursor|null} map) and owns nothing else; a package with a different merge (ranked/relevance fusion) registers its own handle from its own boot and the composite names it. Supersede, because a host replacing the ordered default for its own list is a legitimate override and the displaced plug stays readable through superseded().',
    order: 14,
)]
class MergeStrategyRegistry implements Registry
{
    /** @var BasicRegistry<MergeStrategy> */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);

        $this->register('ordered', new OrderedMergeStrategy, by: self::class);
    }

    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->store->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    /**
     * The strategy at `$key`, throwing on a miss.
     *
     * The throwing half is the right one here and the rule says why: a composite's merge handle is a
     * value the CODE chose (a declaration in a backing class), not one that arrived from a request — so
     * a miss is a wiring error to be named, not an absence to be tolerated.
     */
    public function resolve(RegistryKey|string $key): MergeStrategy
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): ?MergeStrategy
    {
        return $this->store->tryResolve($key);
    }

    /** @return list<MergeStrategy> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }
}
