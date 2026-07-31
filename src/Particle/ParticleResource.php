<?php

declare(strict_types=1);

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Database\Eloquent\Model;
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
     * @param  (Closure(mixed $model, mixed $input, mixed $actor): void)|null  $prepare  before-write hook
     * @param  (Closure(mixed $model, mixed $input): void)|null  $afterWrite  after-write relation-sync hook
     * @param  (Closure(Model): Data)|null  $project  a
     *                                                custom row→Data projector for a Data class with a named constructor (e.g.
     *                                                `ScaffoldPackData::fromScaffoldPack`); takes precedence over {@see $data}
     */
    public function __construct(
        public readonly string $key,
        public readonly string $model,
        public readonly ?string $data = null,
        public readonly ?string $input = null,
        public readonly array $includes = [],
        public readonly bool $filterable = true,
        public readonly int $perPage = 20,
        public readonly ?Closure $prepare = null,
        public readonly ?Closure $afterWrite = null,
        public readonly ?Closure $project = null,
    ) {}
}
