<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Particle\Backing\ResourceBacking;

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
 *   3. **frame** — a zero-glue admin resource skips this entirely and declares a Frame `#[ParticleResource]`,
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
 * {@see toResourceDefinition()}. These were previously carried by a separate admin-resource subtype,
 * which RDU-01 merged UP into this one class and RDU-07 deleted.
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
     * @param  ResourceBacking|class-string  $backing  WHAT backs this resource — a
     *                                                 {@see ResourceBacking} (instance or class-string, resolved at
     *                                                 request time) or an Eloquent model class-string, which is
     *                                                 wrapped in {@see EloquentBacking}. One polymorphic slot,
     *                                                 replacing the `model` / `source` / `sourceKind` triple and its
     *                                                 `model` XOR `source` contract (ticket 11). The ordinary case
     *                                                 still reads `backing: Silo::class`; only a resource whose rows
     *                                                 are not one plain model names a backing class.
     * @param  class-string|null  $data  the read/output spatie Data class; null ⇒ the hydrator resolves it
     *                                   off beam's `#[ParticleResource]` registry (record → its projection class)
     * @param  class-string|null  $input  the input spatie Data DTO (its `toModelAttributes()` maps the
     *                                    request to columns); null ⇒ snake-map the request body
     * @param  list<string>  $includes  default includes — compiled to BOTH the eager-load and the
     *                                  serialization axis by the hydrator (one list, no double-declaration)
     * @param  bool  $filterable  index rides the data-filters builder (`DataFilter::query($key)`) when
     *                            true; a plain `latest()` query otherwise (for resources with no declared
     *                            filter surface)
     * @param  int  $perPage  default page size
     * @param  (Closure(mixed $model, mixed $input, mixed $actor): void)|null  $prepare  before-write hook
     * @param  (Closure(mixed $model, mixed $input): void)|null  $afterWrite  after-write relation-sync hook
     * @param  (Closure(Model): Data)|null  $project  a
     *                                                custom row→Data projector for a Data class with a named constructor (e.g.
     *                                                `ScaffoldPackData::fromScaffoldPack`); takes precedence over {@see $data}
     * @param  (Closure(Builder, ?string $realm): Builder)|null  $scope
     *                                                                   a row-level authorization scope applied to the SUBJECT-RESOLUTION base query the generic handler
     *                                                                   uses for `show`/`update`/`destroy` `findOrFail($id)` (ADR-0156 §83 mutation-scope widening). Absent
     *                                                                   the request-filter surface, this closure is the ONLY guard that keeps a Frame edit/revoke from
     *                                                                   reaching a row the caller may not touch — required for a resource whose model table is shared across
     *                                                                   callers/tenants (e.g. the central Sanctum token table: scope to the acting user's own tokens so a
     *                                                                   revoke-by-id can never delete another user's token). null (default) ⇒ the unscoped `model::query()`,
     *                                                                   so every existing resource is unchanged. Independent of {@see $filterable} (which scopes only the
     *                                                                   list index): a resource may scope both, either, or neither. The optional second `$realm` param
     *                                                                   carries the resolved editor realm (e.g. `'admin'` vs `'tenant'`) so a shared resource can vary its
     *                                                                   row-level scope by realm (e.g. "My Teams" vs "Members" reading the same table under different
     *                                                                   realms); a closure that only declares one param is still called correctly — PHP ignores the
     *                                                                   uncalled extra arg.
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
     * @param  string  $singularLabel  display SINGULAR for docs/titles, for the mass/irregular nouns the inflector mangles (`media` singularizes to "Medium"; declaring `singularLabel: 'Media'` titles the download op "Media Download"). Empty (default) ⇒ inflect from the label/key as before. Display-only: carries NO framing/nav semantics ({@see isFramed()} still reads {@see $label} alone).
     * @param  string|null  $routeKey  the column the `{id}` path segment resolves against for
     *                                 `show`/`update`/`destroy` — a **declared route key**, the resource
     *                                 saying how it is NAMED (never where it is hung; that is the mount's
     *                                 concern). null (default) ⇒ `findOrFail($id)` against the primary key,
     *                                 today's exact path for every existing resource. A non-null column (e.g.
     *                                 `'slug'`) switches subject resolution to `where($routeKey, $id)->firstOrFail()`
     *                                 — and the PK stops resolving entirely, which is the point: one public
     *                                 identifier per resource, never two. Everything upstream is unchanged, so
     *                                 the lookup still runs THROUGH the relative base query and the {@see $scope}
     *                                 gate — which is what lets a route key be unique only **per parent** (a
     *                                 product slug unique per seller under `/sellers/{seller}/extensions/{slug}`)
     *                                 rather than globally.
     */
    public function __construct(
        public string $key,
        public ResourceBacking|string $backing,
        public ?string $data = null,
        public ?string $input = null,
        public array $includes = [],
        public bool $filterable = true,
        public int $perPage = 20,
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
        public string $singularLabel = '',
        public ?string $routeKey = null,
    ) {}

    /**
     * Resolve this declaration's {@see $backing} to a live {@see ResourceBacking}.
     *
     * Resolution is per-call and happens at REQUEST time, never at boot, so a backing may take
     * constructor injection (the tenant connection, its sub-source repos).
     */
    public function backing(): ResourceBacking
    {
        return (new BackingResolver)->resolve($this->backing);
    }

    /**
     * The Eloquent model this resource is backed by, or null when its backing declares no single model.
     *
     * The successor to the old `$model` field for the readers that genuinely need a model class —
     * subject resolution, the doc-generation route-key probe, {@see ModelResourceIndex}. It is
     * **nullable**, which the field was not: `ParticleResource::$model` was a required, non-nullable
     * `string`, and that was the blocker preventing the two declaration types from merging, since
     * `members` and `review-queue` ship no model at all (ticket 11 §A10).
     *
     * ⚠️ A caller that only wants to QUERY should ask {@see backing()} instead. Reaching for the model
     * class to build `Model::query()` by hand re-creates the assumption that every resource is one
     * plain model — the assumption this slot exists to remove.
     *
     * @return class-string|null
     */
    public function modelClass(): ?string
    {
        return (new BackingResolver)->modelFor($this->backing);
    }

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
     * manifest machinery (Frame renders what it is handed). Creatable unless declared {@see $readOnly}
     * (ADR-0156 §83).
     *
     * `sourceKind`/`source` are no longer passed because frame no longer HAS them (ticket 13 steps 5+7):
     * they were beam's contract living in frame's class, with zero branching readers, and the backing
     * they discriminated is now a type rather than a pair of strings.
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
            model: $this->modelClass(),
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
