<?php

namespace Splicewire\Beam\Rendering;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The registry `Route::resourceRenderings()` enumerates. Renderings are keyed by resource — the same
 * token the macro is called with — and resolved through the container on demand, deliberately mirroring
 * how a profile-style registry is seeded from config: seeded from
 * `splicewire.composition.renderings` config, with an imperative {@see register()} for a package (e.g.
 * the lens registry) that discovers its renderings at boot rather than in config.
 *
 * The point of the indirection is that a route file names a RESOURCE, never a rendering. Adding a
 * rendering — by config entry or by `register()` — mounts its route on the next route build with no
 * route-file edit; the enumeration is the registry, not a hand-kept list per resource.
 *
 * The kernel ships no renderings of its own (engine/host seam), exactly as it ships no profiles.
 */
#[IsRegistry(
    root: 'beam.renderings',
    of: 'renderings per resource — the set Particle::renderings() mounts one route each from',
    arity: [RegistryArity::PickOne, RegistryArity::RunAll],
    entryType: 'class-string<'.ResourceRendering::class.'>|'.ResourceRendering::class,
    onDuplicate: OnDuplicate::Admit,
    note: 'One of the three descriptors registry-kernel ticket 07 D-final found LYING about its seam: it '
        .'is filled from `beam.core.renderings` config AND by imperative register(), not one way. The '
        .'read is TWO steps: PickOne selects a resource, then RunAll engages that resource\'s renderings '
        .'— the bare RunAll ticket 47 found while verifying PipelineRegistry\'s. `entryType` says '
        .'class-string-OR-instance rather than the `mixed` it used to say, because a class-string here '
        .'is a LAZY HOLDER of the same type and not a second entry type (47 measured the estate and '
        .'found no registry that holds two, so the field stays a scalar). `$resolved` is a memoization '
        .'cache beside the entries rather than a second keyspace.',
    order: 22,
)]
class ResourceRenderingRegistry implements Filled, Gated, Registry
{
    /**
     * The entries, held as a FIELD rather than inherited (registry-kernel ticket 01 D1) — this class
     * carries rendering vocabulary (`for()`/`find()`/`resources()`) no kernel base class could supply.
     *
     * Every rendering for one resource is registered at the SAME key under `OnDuplicate::Admit`, so
     * `matches($resource)` gives them back in registration order — which is what the two-step read this
     * registry declares (`PickOne` a resource, then `RunAll` its renderings) means.
     */
    private BasicRegistry $entries;

    /** @var array<string, list<ResourceRendering>> */
    private array $resolved = [];

    /** @var list<Registrar> */
    private array $registrars = [];

    /**
     * @param  array<string, list<class-string<ResourceRendering>>|class-string<ResourceRendering>>  $renderings
     *                                                                                                            resource => class-strings. Beam's own provider leaves this EMPTY and
     *                                                                                                            attaches a {@see ConfigRegistrar}
     *                                                                                                            in `boot()` instead (registry-kernel ticket 53); the parameter stays
     *                                                                                                            for a host or test constructing a seeded registry directly.
     */
    public function __construct(array $renderings = [])
    {
        $this->entries = BasicRegistry::for($this);

        foreach ($renderings as $resource => $classes) {
            $this->register($resource, $classes, by: static::class);
        }
    }

    /**
     * Add a rendering — or, from a registrar, a resource's whole LIST of them — for a resource. Accepts
     * an instance or a class-string; class-strings stay lazy so registration never forces a container
     * resolve during another package's `register()`.
     *
     * ## Why a list is accepted here and not in the kernel
     *
     * `beam.core.renderings` is `resource => [class, class]`, and {@see ConfigRegistrar}
     * is a flat `key => entry` reader — a PHP array cannot repeat a key, so a `RunAll` registry whose
     * config holds several entries per key can never be filled one-array-one-entry. The kernel refuses to
     * flatten on a host's behalf (`ConfigRegistrar`'s own docblock: *"a host that wants a nested config
     * flattens it before handing it over, in its own vocabulary"*), and ordinal leaf keys would have been
     * the guess `ConfigRegistry::keyFor()` refuses at exactly this altitude. So the expansion happens
     * HERE, in the owner, where "a resource has several renderings" is vocabulary rather than a guess —
     * and the STORED entry stays one rendering, so `entryType` remains a scalar (ticket 47).
     *
     * @param  class-string<ResourceRendering>|ResourceRendering|list<class-string<ResourceRendering>|ResourceRendering>  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        foreach (is_array($entry) ? $entry : [$entry] as $rendering) {
            if (! is_string($rendering) && ! $rendering instanceof ResourceRendering) {
                throw new InvalidArgumentException(sprintf(
                    'A rendering registered for resource [%s] must be a %s or its class-string; %s given.',
                    (string) $key,
                    ResourceRendering::class,
                    get_debug_type($rendering),
                ));
            }

            $this->entries->register($key, $rendering, $by, $ability);
        }

        // Blunt on purpose: the memo is keyed by the caller's resource token and a write may arrive
        // spelled absolutely, so there is no single slot to invalidate without re-deriving the caller's
        // vocabulary. The map holds one entry per mounted resource; rebuilding it costs a container
        // resolve per rendering, once.
        $this->resolved = [];

        return $this;
    }

    // ── The kernel contract (registry-kernel ticket 53) ─────────────────────────────────────────────

    public function has(RegistryKey|string $key): bool
    {
        return Key::tryParse((string) $key) !== null && $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    /** @return list<class-string<ResourceRendering>|ResourceRendering> */
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
     * Attach a registrar and let it fill THIS registry — not the composed store — now.
     *
     * ⚠️ The delegation trap, measured on registry-kernel ticket 53: `$this->entries->attach($r)` reads
     * naturally and is wrong, because `BasicRegistry::attach()` hands the registrar the STORE, so every
     * write bypasses this class's own `register()` — and with it the list expansion `register()` performs. A composing owner attaches to
     * itself and keeps the registrar list; only the eagerness is inherited.
     */
    public function attach(Registrar $registrar): void
    {
        $this->registrars[] = $registrar;

        $registrar->fill($this);

        $this->resolved = [];
    }

    /** @return list<Registrar> */
    public function registrars(): array
    {
        return $this->registrars;
    }

    /**
     * Every rendering declared for a resource, in registration order.
     *
     * @return list<ResourceRendering>
     */
    public function for(string $resource): array
    {
        if (isset($this->resolved[$resource])) {
            return $this->resolved[$resource];
        }

        $renderings = [];

        foreach (Key::tryParse($resource) === null ? [] : $this->entries->matches($resource) as $entry) {
            $rendering = is_string($entry) ? app($entry) : $entry;

            if (! $rendering instanceof ResourceRendering) {
                throw new InvalidArgumentException(sprintf(
                    'Rendering [%s] registered for resource [%s] does not implement %s.',
                    is_string($entry) ? $entry : $entry::class,
                    $resource,
                    ResourceRendering::class,
                ));
            }

            $renderings[] = $rendering;
        }

        return $this->resolved[$resource] = $renderings;
    }

    /** One named rendering for a resource, or null. The controller's request-time lookup. */
    public function find(string $resource, string $name): ?ResourceRendering
    {
        foreach ($this->for($resource) as $rendering) {
            if ($rendering->name() === $name) {
                return $rendering;
            }
        }

        return null;
    }

    /** @return list<string> the resources carrying at least one rendering */
    public function resources(): array
    {
        return $this->entries->relativeKeys();
    }
}
