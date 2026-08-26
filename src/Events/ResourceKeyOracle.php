<?php

namespace Splicewire\Beam\Events;

use Illuminate\Contracts\Container\Container;
use Rushing\Popcorn\Registries\Key;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Throwable;

/**
 * "Is `compositions` a live resource key?" — existence only, no model lookup.
 *
 * This is the collaborator that makes {@see EventTypeRegistry}'s registration-time validation mean
 * something: an event name whose prefix is not a resource the host actually serves is a stored
 * subscription pointed at nothing, and the day the key is renamed underneath it, an orphan.
 *
 * ## Why it consults SEVERAL registries rather than one
 *
 * Because the keys are scattered, and that is a fact about the estate rather than a defect this class
 * should paper over (api-surface-coherence ticket 14 §4). `ParticleResourceRegistry` holds the bulk;
 * `ResourceRenderingRegistry` holds resources that exist only as a rendering subject (`compositions` is
 * the live example — the `Composition` model registers in the particle registry under a *different*
 * key); Frame's read-only {@see ResourceRegistry} port is consulted because a host may bind a producer
 * beam does not own. Beam binds that port to its own particle registry by default, so the third source
 * is normally a no-op and is here for the host that replaces it.
 *
 * ⚠️ **The ticket that chartered this named a `FrameResourceRegistry` as the home of `tenants`. There is
 * no such class** — Frame ships a `Contracts\ResourceRegistry` *port* and beam aliases it onto
 * {@see ParticleResourceRegistryAdapter}. `tenants` is in the particle registry,
 * which the same ticket also says two paragraphs later. The three-source sweep survives the correction;
 * only the name did not.
 *
 * ## Everything is resolved lazily and defensively
 *
 * Validation runs at *registration* time, which for a package provider is boot — and a registry that
 * blew up because an optional collaborator was absent would make the event catalog a load-order puzzle.
 * Every source is behind `bound()` and a `try`, and an unresolvable source contributes no keys rather
 * than an exception.
 */
class ResourceKeyOracle
{
    /** @var list<string>|null */
    private ?array $cached = null;

    public function __construct(private Container $container) {}

    /** Existence, by exact key. A key that is not even a legal segment is simply not known. */
    public function knows(string $resourceKey): bool
    {
        if ($resourceKey === '' || Key::tryParse($resourceKey) === null) {
            return false;
        }

        return in_array($resourceKey, $this->keys(), true);
    }

    /**
     * Every live resource key, de-duplicated, for a diagnostic that wants to say what WAS available.
     *
     * Memoised per instance, and {@see forget()} clears it: the oracle is a singleton and the particle
     * registry keeps filling after beam's own boot (a consumer package registers in its boot), so a
     * cache taken at the first validation would be a snapshot of beam's half of the estate.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->cached ??= $this->collect();
    }

    public function forget(): void
    {
        $this->cached = null;
    }

    /** @return list<string> */
    private function collect(): array
    {
        $keys = [];

        foreach ($this->particleKeys() as $key) {
            $keys[$key] = true;
        }

        foreach ($this->renderingKeys() as $key) {
            $keys[$key] = true;
        }

        foreach ($this->framePortKeys() as $key) {
            $keys[$key] = true;
        }

        return array_values(array_map('strval', array_keys($keys)));
    }

    /** @return list<string> */
    private function particleKeys(): array
    {
        $registry = $this->source(ParticleResourceRegistry::class);

        if (! $registry instanceof ParticleResourceRegistry) {
            return [];
        }

        return array_values(array_map(
            fn ($resource) => (string) $resource->key,
            $registry->all(),
        ));
    }

    /**
     * A rendering key is `{resource}.{rendering}`, so the RESOURCE is segment one — the same
     * segment-one reading {@see EventType::resourceKey()} uses on an event name.
     *
     * @return list<string>
     */
    private function renderingKeys(): array
    {
        $registry = $this->source(ResourceRenderingRegistry::class);

        if (! $registry instanceof ResourceRenderingRegistry) {
            return [];
        }

        $keys = [];

        foreach ($registry->resources() as $relative) {
            $first = explode('.', $relative)[0] ?? '';

            if ($first !== '') {
                $keys[$first] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /** @return list<string> */
    private function framePortKeys(): array
    {
        $registry = $this->source(ResourceRegistry::class);

        if (! $registry instanceof ResourceRegistry) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($definition) => is_object($definition) && property_exists($definition, 'key')
                ? (string) $definition->key
                : '',
            $registry->all(),
        )));
    }

    private function source(string $abstract): ?object
    {
        if (! $this->container->bound($abstract)) {
            return null;
        }

        try {
            return $this->container->make($abstract);
        } catch (Throwable) {
            return null;
        }
    }
}
