<?php

namespace Splicewire\Beam\Particle;

use InvalidArgumentException;
use RuntimeException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RecordsSupersession;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\Superseded;

/**
 * The container-singleton registry of {@see ParticleRelative} declarations — one entry per **relative
 * edge**, keyed `{parent}.{child}` under the stamped root `beam.particle.relatives`
 * (api-surface-coherence ticket 50).
 *
 * ## It is deliberately the op registry's twin, down to the shape
 *
 * {@see ParticleOperationRegistry} composes a {@see BasicRegistry} as a FIELD (registry-kernel 01 D1
 * sanctions composition where the class carries vocabulary no kernel base could supply), self-keys off
 * the entry via {@see HasRegistryKey}, and supersedes on duplicate. This does all four, because the
 * ticket's charter is that **two registries of identical shape migrate as one archetype** when
 * registry-kernel's per-resource-registry sweep runs. Divergence here would buy nothing and cost that.
 *
 * ⚠️ The ticket specified the key as `{parent}:{child}` "mirroring `ParticleOperationRegistry`
 * (`{resource}:{name}`) byte-for-byte" — written before the op registry dotted its own separator. The
 * mirror is the DOT; see {@see ParticleRelative::registryKey()} for why transcribing the literal
 * characters would have been the wrong kind of faithfulness.
 *
 * ## Arity is one step, and stays scalar
 *
 * `<parent>.<child>` is a FLAT keyspace: a read picks one edge by its full address in one step.
 * Enumerating a parent's edges is `matches('…relatives.<parent>')` — the same one-step read scoped to a
 * branch, not a second arity member (registry-kernel ticket 47's rule).
 *
 * ## `Supersede`, and what it means here specifically
 *
 * On the op registry, registering over a key is how a package OVERRIDES an operation it does not own.
 * Here it is narrower and worth stating: an edge is addressed by its two ENDS, so a second declaration
 * of `fragments.media` is a second opinion about the same coupling, and the later one wins. Two
 * genuinely different edges between the same two resources are therefore **not** expressible today, and
 * that is a real limit rather than an oversight — the live population is one, and inventing a
 * disambiguating segment for a case nobody has would be keyspace invented against a guess.
 * The supersession record ({@see Superseded}) makes the collision auditable if it ever arrives.
 */
#[IsRegistry(
    root: 'beam.particle.relatives',
    of: 'declared relative edges — a child particle resource mounted under a route-model-bound parent',
    arity: RegistryArity::PickOne,
    entryType: ParticleRelative::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Keys are `<parent>.<child>` under the stamped root, off the self-keying entry '
        .'({@see ParticleRelative::registryKey()}). The edge is declared by the COUPLING OWNER — never '
        .'by the child\'s package or the parent\'s, because either would force one tier to name the '
        .'other (api-surface-coherence ticket 50 / 18 §D1). Nothing about an edge lives on '
        .'`#[ParticleResource]`. An edge whose `via` is behavioural rides as the DECLARING CLASS NAME in '
        .'the route defaults rather than as a Closure, which is what keeps the table `route:cache`-able '
        .'(ticket 51 §2). `Supersede` means a second declaration of one `<parent>.<child>` pair is a '
        .'second opinion about the same coupling and the later wins; two distinct edges between one pair '
        .'are not expressible, deliberately, at a live population of one.',
    order: 14,
)]
class ParticleRelativeRegistry implements Gated, RecordsSupersession, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    // ── REST tier — the vocabulary a kernel base class could not supply ────────────────────────────

    public function get(string $parent, string $child): ParticleRelative
    {
        return $this->lookup($parent, $child)
            ?? throw new RuntimeException("No particle relative [{$child}] declared on parent [{$parent}].");
    }

    public function has(RegistryKey|string $parent, string $child = ''): bool
    {
        return $this->lookup($parent, $child) !== null;
    }

    /**
     * The non-throwing twin of {@see get()} — for callers that ASK rather than demand, on
     * {@see ParticleOperationRegistry::find()}'s precedent.
     */
    public function find(string $parent, string $child): ?ParticleRelative
    {
        return $this->lookup($parent, $child);
    }

    /**
     * A READ answers "absent" for a key that is not even a legal address rather than throwing;
     * REGISTRATION still throws on a malformed key, because a declaration that cannot be addressed is a
     * defect and must be loud. Same line the two sibling registries draw.
     */
    private function lookup(RegistryKey|string $parent, string $child): ?ParticleRelative
    {
        $key = $parent instanceof RegistryKey
            ? $parent
            : ($child === '' ? $parent : "{$parent}.{$child}");

        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->entries->tryResolve($key);
    }

    /**
     * Every declared edge, registration order — for auditors walking the DECLARED set.
     *
     * @return list<ParticleRelative>
     */
    public function all(): array
    {
        return $this->entries->matches($this->entries->root());
    }

    /**
     * Every edge declared against one PARENT, registration order — what
     * `Particle::relatives('fragments')` reads, and the read the dotted key bought.
     *
     * @return list<ParticleRelative>
     */
    public function forParent(string $parent): array
    {
        if (Key::tryParse($parent) === null) {
            return [];
        }

        return $this->entries->matches($parent);
    }

    // ── The kernel contract (registry-kernel ticket 53) ────────────────────────────────────────────

    /**
     * Store an edge. The one-argument spelling `register($relative)` is the natural call; the contract
     * spelling `register('fragments.media', $relative, by: …)` works too, which is what lets a
     * {@see Registrar} fill this registry at all. The key is never concatenated here — the entry keys
     * itself.
     */
    public function register(RegistryKey|string|ParticleRelative $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof ParticleRelative) {
            $relative = $key;
            $address = $relative->registryKey();
        } else {
            $relative = $entry;
            $address = $key;

            if (! $relative instanceof ParticleRelative) {
                throw new InvalidArgumentException(sprintf(
                    'ParticleRelativeRegistry stores ParticleRelative declarations; `%s` was given for key [%s].',
                    get_debug_type($entry),
                    (string) $key,
                ));
            }
        }

        $this->entries->register($address, $relative, $by, $ability);

        return $this;
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    /** @return list<ParticleRelative> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->entries = $this->entries->unfiltered();

        return $unfiltered;
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The entries displaced at `$key`, oldest first — what makes "something overrode this edge" a fact a
     * reader can check rather than an inference from provider boot order.
     *
     * @return list<Superseded>
     */
    public function superseded(RegistryKey|string $key): array
    {
        return $this->entries->superseded($key);
    }
}
