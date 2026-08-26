<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\ParticleRelative as ParticleRelativeRuntime;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;

/**
 * Marks a class as a **declared relative edge** — one child particle resource mounted under a
 * route-model-bound parent (api-surface-coherence ticket 50, decided at [18 §D1/§D2]).
 *
 * ```php
 * #[ParticleRelative(child: 'media', of: 'fragments', model: Fragment::class, via: 'media')]
 * class FragmentMediaRelative {}
 * ```
 *
 * Boot-time discovery reflects it into a runtime {@see ParticleRelativeRuntime} on the
 * {@see ParticleRelativeRegistry}, and `Particle::relatives('fragments')` mounts every edge declared
 * against that parent — which is what lets a host route file stop restating a coupling the declaration
 * already states.
 *
 * The attribute twin of `$registry->register(new ParticleRelative(...))`, and the exact sibling of
 * {@see ParticleOp}: same discovery seam, same `public static` convention-method rule for the one thing
 * an attribute cannot carry.
 *
 * ## The convention method
 *
 * ```php
 * public static function via(Model $parent, Builder $query): Builder
 * ```
 *
 * OPTIONAL, and mutually exclusive with a `via:` string. Declare it when the edge is a scope rather than
 * a named relation. Because one class holds exactly one edge, the method needs no per-edge name — the
 * same seam `#[ParticleResource]` uses for `scope`/`project`/`prepare`/`afterWrite`.
 *
 * ⚠️ **Declaring it is what keeps the table cacheable.** An inline Closure `via:` on the imperative
 * `Particle::relative(…)` spelling rides the route DEFAULTS, and `route:cache` cannot serialize a
 * Closure (api-surface-coherence ticket 51 §2). A convention method rides as this CLASS's name instead,
 * which serializes fine and is resolved back per request. A behavioural edge and a green `route:cache`
 * are only compatible through this declaration site.
 *
 * ## Who declares it
 *
 * The **coupling owner** — never the child's package and never the parent's. See
 * {@see ParticleRelativeRuntime}'s docblock for why that is a tier rule and not a preference, and why
 * nothing was added to `#[ParticleResource]` to hold it.
 *
 * @see ParticleMounter::relatives() the mount side
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ParticleRelative
{
    /**
     * @param  string  $child  the CHILD particle resource key mounted under the parent
     * @param  string  $of  the PARENT particle resource key (and, by default, its URI segment)
     * @param  class-string  $model  the parent model the bound `{binding}` segment resolves to
     * @param  string|null  $via  the relation NAME on the parent; omit to declare a `public static via()`
     * @param  string|null  $binding  the route parameter claimed for the parent (default: the model's
     *                                kebab basename). ⚠️ app-global — ticket 51 §1
     * @param  string|null  $at  the parent's URI segment when it differs from `$of`
     * @param  array<int, string>|null  $only  which of the child's five CRUD verbs to mount; null ⇒ all
     * @param  string|null  $names  the child's route-name stem — ⚠️ give it one, or this exposure derives
     *                              the same names as the child's flat mount and last-wins hides one of
     *                              them (ticket 51 §3)
     * @param  string|null  $idConstraint  `'uuid'` constrains the child's `{id}`
     * @param  string|null  $childAt  the child's URI segment when it differs from `$child`
     */
    public function __construct(
        public string $child,
        public string $of,
        public string $model,
        public ?string $via = null,
        public ?string $binding = null,
        public ?string $at = null,
        public ?array $only = null,
        public ?string $names = null,
        public ?string $idConstraint = null,
        public ?string $childAt = null,
    ) {}
}
