<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Marks a Data class as a **REST particle resource** — the attribute twin of the imperative
 * `$registry->register(new ParticleResource(...))` call (ADR-0116). Placed on the resource's read/index
 * projection Data class; boot-time discovery reflects it into a runtime
 * {@see \Splicewire\Beam\Particle\ParticleResource} and registers that into the
 * {@see ParticleResourceRegistry}, so the generic
 * {@see ParticleController} serves it — no per-resource controller AND no
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
     * @param  string|null  $defaultSort  the column the non-filterable index orders by (descending); null ⇒
     *                                    `created_at`. Use `updated_at` for a "most-recently-edited first" list.
     *
     * The remaining params are the optional editor/**manifest** concerns (RDU-02), mirroring the runtime
     * {@see \Splicewire\Beam\Particle\ParticleResource} one-for-one — so a resource can be fully described
     * declaratively ON its Data class (nav + edit surface + gates), not just its REST core. A resource that
     * declares a non-empty {@see $label} is FRAMED (it lights up the `@schemastud/frame` editor) and projects
     * into Frame's manifest; a REST-only surface leaves them at their defaults. The closure hooks stay
     * resolved from `public static` convention methods (attributes can't carry closures); these scalar
     * manifest fields are carried directly.
     * @param  string  $label  nav label; a non-empty label marks the resource framed (navigable)
     * @param  'enriched'|'bare'  $form  per-resource default form mode
     * @param  class-string|null  $editData  rare escape-hatch edit DTO (input-shape divergence)
     * @param  string|null  $policy  ability/policy key the injected can() resolves against
     * @param  class-string|null  $query  data-filters query class the ListShell facets bar rides (manifest)
     * @param  string|null  $group  nav group heading
     * @param  string|null  $icon  nav icon key
     * @param  string|null  $section  host sitemap section this resource auto-attaches into; null = not in primary nav
     * @param  int|null  $navOrder  placement within the section
     * @param  string|null  $routeName  stable route identity a host binds the generated leaf under
     * @param  'single'|'subnav'|'master-detail'|null  $layout  inner-layout grammar emitted on the ContextManifest
     * @param  bool  $readOnly  a machine-authored / view-only resource — no create/edit/delete through Frame (ADR-0156 §83)
     * @param  bool|null  $deletable  Frame destroy gate, INDEPENDENT of $readOnly (ADR-0156 §83 delete-independent widening); null follows the create gate
     * @param  bool|null  $editable  Frame update gate, INDEPENDENT of $readOnly (ADR-0156 §83 edit-independent widening); null follows the create gate
     * @param  bool  $showable  Frame per-record detail gate, INDEPENDENT of $readOnly/$editable (F04 show-independent widening); defaults true
     * @param  bool|null  $frame  explicit override for the framed predicate: true forces framed even with an empty label, false forces REST-only even with a label; null ⇒ framed iff the label is non-empty
     */
    public function __construct(
        public string $key,
        public string $model,
        public ?string $data = null,
        public ?string $input = null,
        public array $includes = [],
        public bool $filterable = true,
        public int $perPage = 20,
        public ?string $defaultSort = null,
        public string $label = '',
        public string $form = 'bare',
        public ?string $editData = null,
        public ?string $policy = null,
        public ?string $query = null,
        public ?string $group = null,
        public ?string $icon = null,
        public ?string $section = null,
        public ?int $navOrder = null,
        public ?string $routeName = null,
        public ?string $layout = null,
        public bool $readOnly = false,
        public ?bool $deletable = null,
        public ?bool $editable = null,
        public bool $showable = true,
        public ?bool $frame = null,
    ) {}
}
