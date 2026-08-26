<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Particle\Attributes\ParticleRelative as ParticleRelativeAttribute;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

/**
 * A declared **relative edge**: one child particle resource, mounted underneath a route-model-bound
 * parent (api-surface-coherence ticket 50, decided at [18 §D1/§D2]).
 *
 * ```
 * /api/v1/fragments/{fragment}/media      ← child `media`, parent `fragments`, via the `media` relation
 * ```
 *
 * The runtime twin of {@see ParticleRelativeAttribute}, and the thing
 * {@see ParticleMounter::relatives()} mounts. Before this class the edge was a hand-written
 * `Route::particleRelative(…)` call in a host route file: three facts about a relationship between two
 * resources, stated as a mount rather than as a declaration, and therefore invisible to anything that
 * did not read the route file.
 *
 * ## Who the honest declarant is — the whole argument for this shape
 *
 * The edge is **not** a fact about the child and **not** a fact about the parent. `fragments`/`media`
 * couples `splicewire/laravel-satellite-knowledge`'s `Fragment` to `splicewire/laravel-beam-media`'s
 * generic media resource, and beam-media is deliberately Fragment-agnostic — the tier boundary the
 * flagship's route file defended out loud before this existed. Neither package may name the other, so
 * neither package can hold the declaration; **the coupling owner holds it**, which is the host (or
 * whichever package owns the coupling), in its own class.
 *
 * That is also why nothing lands on `#[ParticleResource]`: no `parent:`, no `relatives:`. A `relatives:`
 * array on the child would force beam-media to name `Fragment`, and one on the parent would force
 * knowledge to name `media` — each is the tier violation the other way round. `includes:` is untouched:
 * it is the eager-load/serialization axis, a different concern that merely also names relations.
 *
 * ⚠️ **This is n=1 going on 2, and that is deliberately not the justification.** Ticket 18 §D1 recorded
 * the reasoning so a later session does not re-raise the attribute on volume: the edge attribute is
 * justified by *who can honestly declare it*, not by how many edges exist.
 *
 * ## One edge, one class — which is what makes a closure `via:` free
 *
 * Each edge is its own class, so it has exactly ONE `via`, so the closure form needs no per-edge name:
 * it is a `public static function via()` on the declaring class, resolved by the same convention seam
 * `#[ParticleResource]` already institutionalizes for `scope`/`project`/`prepare`/`afterWrite`.
 *
 * That convention is not a stylistic echo — it is what makes the closure form **cacheable**. A Closure
 * cannot ride the route defaults (`route:cache` serializes them and dies on it — api-surface-coherence
 * ticket 51 §2), but this class's NAME can, and the controller resolves the static method back off it
 * per request. So a behavioural edge and a `route:cache`-green table stopped being mutually exclusive
 * the moment the edge got a class to live on.
 *
 * @see ParticleRelativeRegistry the flat `{parent}.{child}` registry this accumulates into
 */
class ParticleRelative implements HasRegistryKey
{
    /**
     * @param  string  $child  the CHILD particle resource key — what is mounted under the parent
     * @param  string  $of  the PARENT particle resource key, and by default the parent's URI segment
     * @param  class-string  $model  the parent model the `{binding}` segment resolves to
     * @param  string|Closure|null  $via  the relation NAME on the parent (`'media'` ⇒
     *                                    `$fragment->media()`), a scope Closure, or `null` to resolve a
     *                                    `public static via()` off {@see $declaredBy}
     * @param  string|null  $binding  the route parameter name claimed for the parent; defaults to the
     *                                model's kebab basename. ⚠️ **App-global** — ticket 51 §1 owns that
     *                                ruling and {@see ParticleMounter::claimBinding()} ledgers it
     * @param  string|null  $at  the parent's URI segment when it differs from `$of`
     * @param  array<int, string>|null  $only  which of the child's five CRUD verbs to mount; null ⇒ all
     * @param  string|null  $names  the child's route-name stem. ⚠️ **Give it one.** Absent a stem the
     *                              child derives its names from its own resource key, which is the same
     *                              name its FLAT mount derives — and Laravel's name table is last-wins,
     *                              so one of the two exposures silently becomes unaddressable. That
     *                              exact collision was two of ticket 51 §3's six duplicates
     * @param  string|null  $idConstraint  `'uuid'` constrains the child's `{id}`; null leaves it open
     * @param  string|null  $childAt  the child's URI segment when it differs from `$child`
     * @param  class-string|null  $declaredBy  the annotated class, when this came from the attribute —
     *                                         the serializable reference a Closure `$via` cannot be
     */
    public function __construct(
        public string $child,
        public string $of,
        public string $model,
        public string|Closure|null $via = null,
        public ?string $binding = null,
        public ?string $at = null,
        public ?array $only = null,
        public ?string $names = null,
        public ?string $idConstraint = null,
        public ?string $childAt = null,
        public ?string $declaredBy = null,
    ) {
        if ($this->via === null && $this->declaredBy === null) {
            throw new InvalidArgumentException(
                "Particle relative [{$this->key()}] declares no `via:` and no declaring class to read a "
                .'`public static via()` off. An edge that cannot say how the child hangs off the parent '
                .'cannot mount.'
            );
        }
    }

    /**
     * The edge's own address, root-free — `{parent}.{child}`.
     *
     * Root-free for the reason {@see ParticleOperation::registryKey()} states: the registry stamps
     * `beam.particle.relatives` on the way in, so no declaration site spells the root and moving the
     * root is a one-line edit on the registry's attribute.
     *
     * ⚠️ **The ticket wrote this key as `{parent}:{child}`, "mirroring `ParticleOperationRegistry`
     * (`{resource}:{name}`) byte-for-byte".** That mirror is now a DOT, because the op registry itself
     * moved off the colon (registry-kernel 30 widened the segment charset, and the op registry then
     * dotted its separator to buy branch reads). Mirroring the sibling's *current* shape is what the
     * ticket actually asked for; transcribing its literal characters would have reproduced a keyspace
     * its owner had already abandoned — and would have cost {@see ParticleRelativeRegistry::forParent()}
     * its one-step `matches()`.
     */
    public function registryKey(): RegistryKey
    {
        return Key::parse($this->key());
    }

    /** The same address as a plain string — what error messages interpolate. */
    public function key(): string
    {
        return "{$this->of}.{$this->child}";
    }

    /** The parent's URI segment — `at:` when given, else the parent resource key. */
    public function parentUri(): string
    {
        return $this->at ?? $this->of;
    }

    /** The child's URI segment, relative to the bound parent — `childAt:` when given, else the child key. */
    public function childUri(): string
    {
        return $this->childAt ?? $this->child;
    }

    /** The route parameter this edge claims for the parent — app-global; see the constructor's warning. */
    public function bindingName(): string
    {
        return $this->binding ?? Str::kebab(class_basename($this->model));
    }

    /**
     * What actually gets stamped into the route's `VIA` default.
     *
     * A relation-name string rides as itself. Everything else rides as the **declaring class name** — a
     * serializable reference, which is the whole point (ticket 51 §2). `ParticleController` resolves
     * that name back to the `public static via()` per request.
     *
     * An inline Closure with no declaring class is still allowed through, because the imperative
     * `Particle::relative(…)` spelling has always accepted one and this class is the declaration form,
     * not a new restriction. It makes the route table uncacheable, which is a documented limitation and
     * pinned by a test rather than argued about.
     */
    public function routeVia(): string|Closure
    {
        if (is_string($this->via)) {
            return $this->via;
        }

        if ($this->declaredBy !== null) {
            return $this->declaredBy;
        }

        return $this->via;
    }
}
