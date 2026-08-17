<?php

namespace Splicewire\Beam\Rendering;

use InvalidArgumentException;

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
class ResourceRenderingRegistry
{
    /** @var array<string, list<class-string<ResourceRendering>|ResourceRendering>> */
    private array $entries = [];

    /** @var array<string, list<ResourceRendering>> */
    private array $resolved = [];

    /**
     * @param  array<string, list<class-string<ResourceRendering>>>  $renderings  resource => class-strings
     */
    public function __construct(array $renderings = [])
    {
        foreach ($renderings as $resource => $classes) {
            foreach ((array) $classes as $class) {
                $this->entries[$resource][] = $class;
            }
        }
    }

    /**
     * Add a rendering for a resource. Accepts an instance or a class-string; class-strings stay lazy so
     * registration never forces a container resolve during another package's `register()`.
     *
     * @param  class-string<ResourceRendering>|ResourceRendering  $rendering
     */
    public function register(string $resource, string|ResourceRendering $rendering): self
    {
        $this->entries[$resource][] = $rendering;
        unset($this->resolved[$resource]);

        return $this;
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

        foreach ($this->entries[$resource] ?? [] as $entry) {
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
        return array_values(array_keys($this->entries));
    }
}
