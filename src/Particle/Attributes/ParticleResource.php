<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;

/**
 * Marks a Data class as a **REST particle resource** — the attribute twin of the imperative
 * `$registry->register(new ParticleResource(...))` call (ADR-0116). Placed on the resource's read/index
 * projection Data class; boot-time discovery reflects it into a runtime
 * {@see \Splicewire\Beam\Particle\ParticleResource} and registers that into the
 * {@see \Splicewire\Beam\Particle\ParticleResourceRegistry}, so the generic
 * {@see \Splicewire\Beam\Http\Particle\ParticleController} serves it — no per-resource controller AND no
 * per-resource provider registration.
 *
 * This is the REST/runtime sibling of frame's `#[AdminResource]` (which feeds the admin *manifest*,
 * `ResourceDefinition`). The two are independent transports over the same `ParticleWriter`/hydrator: a
 * surface may carry BOTH (editable admin + REST) or just this one (headless REST).
 *
 * **The closure constraint (load-bearing).** A `ParticleResource`'s power is its closures — `scope`
 * (row-level auth), `project` (row→Data), `prepare` (pre-write), `afterWrite` (relation/media sync). PHP
 * attributes cannot carry closures. So this attribute carries only the SCALAR/class-string declaration;
 * the closures are resolved by CONVENTION from `public static` methods on the SAME annotated class, wired
 * in by {@see AttributedParticleDiscovery} when present (each is optional):
 *
 *   - `public static function scope(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder`  (actor via the `Auth` facade — the beam scope convention)
 *   - `public static function project(\Illuminate\Database\Eloquent\Model $model): \Spatie\LaravelData\Data`
 *   - `public static function prepare(\Illuminate\Database\Eloquent\Model $model, mixed $input, mixed $actor): void`
 *   - `public static function afterWrite(\Illuminate\Database\Eloquent\Model $model, mixed $input): void`
 *
 * The attribute is DECLARATION-only: it registers the resource into the registry. It does NOT mount routes
 * — routing (uri + middleware group) stays a host concern via `Route::particleResource($uri, $key, only:)`,
 * exactly as `#[AdminResource]` registers a declaration while the host mounts the admin leaf.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ParticleResource
{
    /**
     * @param  string  $key  the registry key AND the data-filters resource key the list query rides
     * @param  class-string  $model  the Eloquent model class
     * @param  class-string|null  $data  read/output Data class; null ⇒ the annotated class itself is the
     *                                   projection (the single-class default), unless a static `project()`
     *                                   convention method takes precedence
     * @param  class-string|null  $input  input Data DTO (`toModelAttributes()` write map); null ⇒ snake-map
     * @param  list<string>  $includes  default includes (eager-load + serialization axis)
     * @param  bool  $filterable  index rides the data-filters builder when true; plain `latest()` otherwise
     * @param  int  $perPage  default page size
     */
    public function __construct(
        public string $key,
        public string $model,
        public ?string $data = null,
        public ?string $input = null,
        public array $includes = [],
        public bool $filterable = true,
        public int $perPage = 20,
    ) {}
}
