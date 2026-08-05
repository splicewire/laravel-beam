<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;

/**
 * The declarative description of a REST resource served by {@see \Splicewire\Beam\Http\Particle\ParticleController}.
 *
 * This is the "controller as data" seam: instead of a hand-rolled controller per resource, a resource
 * declares WHAT it is — its model, its read Data class, its input mapping, its list query scope, its
 * default includes, and its optional write hooks — and the generic {@see ParticleController} runs the
 * canonical index/show/store/update/destroy against it, riding the beam `ParticleWriter` (write) +
 * `ParticleHydrator` (read) + the model's policy (deny-default authorization).
 *
 * Three consumption tiers (increasing bespokeness):
 *   1. **inline** — `Route::particleResource('saved-filters', 'saved_filter')` mounts the generic
 *      controller at conventional routes; the resource lives in {@see ParticleResourceRegistry}. No
 *      controller class.
 *   2. **extension** — a controller `extends ParticleController`, returns its resource from
 *      {@see ParticleController::particleResource()}, inherits the CRUD verbs, and adds bespoke actions
 *      that ride the inherited internals.
 *   3. **frame** — a zero-glue admin resource skips this entirely and declares a Frame `#[AdminResource]`,
 *      served by the existing generic `FrameResourceController` (a sibling surface over the same
 *      `ParticleWriter`).
 *
 * Glue that would otherwise force a hand-rolled controller is absorbed by the two hooks:
 *   - {@see $prepare} runs on the fresh/loaded model BEFORE the write — for defaults and pre-save
 *     relation association (e.g. `->owner()->associate($user)`) that mass-fill can't set.
 *   - {@see $afterWrite} runs AFTER the write — for relation syncs (`->sync()`, `attachTags`, …).
 *
 * ## Editor/manifest fields (RDU-01 — the expand half of the expand→contract retire)
 *
 * `ParticleResource` is now the ONE class describing an editable, model-backed resource: on top of the
 * REST/runtime core above it carries the optional **manifest** fields ({@see $label}, {@see $form},
 * {@see $editData}, {@see $policy}, {@see $query}, {@see $group}, {@see $icon}, {@see $section},
 * {@see $navOrder}, {@see $routeName}, {@see $layout}, {@see $readOnly}, {@see $deletable},
 * {@see $editable}, {@see $showable}) and can project itself into Frame's agnostic contract via
 * {@see toResourceDefinition()}. These were previously carried by `ParticleAdminResource`, which is now a
 * thin delegating subclass kept only for backward compatibility (RDU-07 retires it) — see
 * {@see ParticleAdminResource}.
 *
 * A resource is **framed** — i.e. it lights up the `@schemastud/frame` editor in addition to REST — when
 * {@see isFramed()} is true (a non-empty {@see $label}, unless overridden by the explicit {@see $frame}
 * boolean). A REST-only surface leaves `label` empty; it stays a plain particle resource. Beam depends
 * DOWN on frame (ADR-0156), so this class may reference `Schemastud\Frame\*` directly.
 */
class ParticleResource
{
    /**
     * @param  string  $key  the registry key AND the data-filters resource key the list query rides
     *                       (`DataFilter::query($key)`), e.g. `'silo'`
     * @param  class-string  $model  the Eloquent model class
     * @param  class-string|null  $data  the read/output spatie Data class; null ⇒ the hydrator resolves it
     *                                   off beam's `#[AdminResource]` registry (record → its projection class)
     * @param  class-string|null  $input  the input spatie Data DTO (its `toModelAttributes()` maps the
     *                                    request to columns); null ⇒ snake-map the request body
     * @param  list<string>  $includes  default includes — compiled to BOTH the eager-load and the
     *                                  serialization axis by the hydrator (one list, no double-declaration)
     * @param  bool  $filterable  index rides the data-filters builder (`DataFilter::query($key)`) when
     *                            true; a plain `latest()` query otherwise (for resources with no declared
     *                            filter surface)
     * @param  int  $perPage  default page size
     * @param  string|null  $defaultSort  the column the NON-filterable index orders by (descending, via
     *                                    `->latest($col)`); null ⇒ `created_at` (the framework `latest()`
     *                                    default). A filterable resource ignores this — its data-filters
     *                                    query owns ordering. Lets a resource whose list is "most recently
     *                                    EDITED first" declare `updated_at` instead of the create default.
     * @param  (Closure(mixed $model, mixed $input, mixed $actor): void)|null  $prepare  before-write hook
     * @param  (Closure(mixed $model, mixed $input): void)|null  $afterWrite  after-write relation-sync hook
     * @param  (Closure(Model): Data)|null  $project  a
     *                                                custom row→Data projector for a Data class with a named constructor (e.g.
     *                                                `ScaffoldPackData::fromScaffoldPack`); takes precedence over {@see $data}
     * @param  (Closure(Builder): Builder)|null  $scope
     *                                                   a row-level authorization scope applied to the SUBJECT-RESOLUTION base query the generic handler
     *                                                   uses for `show`/`update`/`destroy` `findOrFail($id)` (ADR-0156 §83 mutation-scope widening). Absent
     *                                                   the request-filter surface, this closure is the ONLY guard that keeps a Frame edit/revoke from
     *                                                   reaching a row the caller may not touch — required for a resource whose model table is shared across
     *                                                   callers/tenants (e.g. the central Sanctum token table: scope to the acting user's own tokens so a
     *                                                   revoke-by-id can never delete another user's token). null (default) ⇒ the unscoped `model::query()`,
     *                                                   so every existing resource is unchanged. Independent of {@see $filterable} (which scopes only the
     *                                                   list index): a resource may scope both, either, or neither.
     *
     * The remaining params are the optional editor/**manifest** concerns — a resource that declares a
     * non-empty {@see $label} is {@see isFramed() framed} and projects into Frame via
     * {@see toResourceDefinition()}. A REST-only resource leaves them at their defaults.
     * @param  string  $label  nav label; a non-empty label marks the resource {@see isFramed() framed} (navigable)
     * @param  string  $form  per-resource default form mode: 'enriched' | 'bare'
     * @param  class-string|null  $editData  rare escape-hatch edit DTO (input-shape divergence)
     * @param  string|null  $policy  ability/policy key the injected can() resolves against
     * @param  class-string|null  $query  data-filters query class the ListShell facets bar rides (manifest)
     * @param  string|null  $group  nav group heading
     * @param  string|null  $icon  nav icon key
     * @param  string|null  $section  host sitemap section this resource auto-attaches into; null = not in primary nav
     * @param  int|null  $navOrder  placement within the section
     * @param  string|null  $routeName  stable route identity a host binds the generated leaf under
     * @param  string|null  $layout  inner-layout grammar ('single'|'subnav'|'master-detail'); emitted on the ContextManifest
     * @param  bool  $readOnly  a machine-authored / view-only resource — no create/edit/delete through Frame (ADR-0156 §83). Projects `creatable: !$readOnly`; the generic {@see ParticleFrameResourceHandler} refuses store/update/destroy with a 405 when `! creatable`. Default false — writable.
     * @param  bool|null  $deletable  whether Frame destroy is allowed, INDEPENDENT of $readOnly (ADR-0156 §83 delete-independent widening) — for a prune-but-not-create/edit list. null (default) follows the create gate (`!$readOnly`); an explicit true opens destroy on an otherwise not-creatable resource.
     * @param  bool|null  $editable  whether Frame update (in-place edit) is allowed, INDEPENDENT of $readOnly (ADR-0156 §83 edit-independent widening) — for a create-and-delete-but-not-edit resource (e.g. invitations). null (default) follows the create gate (`!$readOnly`); an explicit false closes in-place edit on an otherwise creatable resource.
     * @param  bool  $showable  whether Frame serves a per-record detail (`records/{id}`, show), INDEPENDENT of $readOnly and $editable (F04 show-independent widening) — so a `readOnly` INSPECT resource (e.g. `operator-customers`) still exposes a detail view even though it 405s store/update/destroy. Defaults true (readable ⇒ showable); set false to make a resource genuinely list-only (no detail route).
     * @param  bool|null  $frame  explicit override for the {@see isFramed() framed} predicate: `true` forces the resource framed even with an empty label, `false` forces it REST-only even with a label; null (default) ⇒ framed iff the label is non-empty.
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
        public ?Closure $prepare = null,
        public ?Closure $afterWrite = null,
        public ?Closure $project = null,
        public ?Closure $scope = null,
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

    /**
     * Is this resource **framed** — i.e. does it light up the `@schemastud/frame` editor (nav + edit
     * surface via {@see toResourceDefinition()}) in addition to the REST transport?
     *
     * By default a resource is framed iff it declares a non-empty {@see $label} (an editable resource is
     * navigable). The explicit {@see $frame} boolean overrides that heuristic in either direction: pass
     * `frame: true` to frame a label-less resource, `frame: false` to keep a labelled one REST-only.
     */
    public function isFramed(): bool
    {
        return $this->frame ?? $this->label !== '';
    }

    /**
     * Project into Frame's agnostic manifest contract. Beam reflects this declaration and *feeds* Frame's
     * manifest machinery (Frame renders what it is handed; it never names a model). Model-backed ⇒
     * `sourceKind: 'model'`, creatable unless declared {@see $readOnly} (ADR-0156 §83).
     *
     * @param  string|null  $realm  ACCEPTED but IGNORED for now (RDU-01) — the projection is identical for
     *                              every realm. The param exists so later issues (realm-addressable
     *                              projection) can vary the contract without changing this signature.
     */
    public function toResourceDefinition(?string $realm = null): ResourceDefinition
    {
        if ($this->isFramed() && $this->data === null) {
            throw new RuntimeException(
                "ParticleResource [{$this->key}] is framed but has no read Data class; a framed resource must declare one (the SchemaDataResolver fallback was removed by ADR-0156)."
            );
        }

        return new ResourceDefinition(
            key: $this->key,
            sourceKind: 'model',
            model: $this->model,
            source: null,
            data: $this->data,
            creatable: ! $this->readOnly,
            deletable: $this->deletable ?? ! $this->readOnly,
            editable: $this->editable ?? ! $this->readOnly,
            showable: $this->showable,
            query: $this->query,
            editData: $this->editData,
            policy: $this->policy,
            form: $this->form,
            nav: new NavMetadata(
                label: $this->label,
                group: $this->group,
                icon: $this->icon,
                section: $this->section,
                navOrder: $this->navOrder,
                routeName: $this->routeName,
            ),
            layout: $this->layout,
        );
    }
}
