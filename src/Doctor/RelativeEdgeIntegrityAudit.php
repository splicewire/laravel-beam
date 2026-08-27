<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleRelative;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * **A declared relative edge that will not scope the way it says it does** —
 * particle-operation-surface 15, from 07's scout layer.
 *
 * `#[ParticleRelative]` mounts a child resource under a route-model-bound parent, and its whole promise
 * is that the child rows a caller sees are the ones hanging off THAT parent. Two declarations can be
 * individually valid and jointly break that promise, and neither the attribute nor the mount can catch
 * it — the fact lives on the *child's* `#[ParticleResource]`, which the edge's author may not own.
 *
 * ## The `filterable` trap, and why it is the reason this audit exists
 *
 * `ParticleController::index()` branches:
 *
 * ```php
 * $query = $resource->filterable
 *     ? $this->hydrator->query($resource->key, $ctx)      // ← $relativeQuery is DISCARDED
 *     : ($relativeQuery ?? $this->defaultSortedQuery($resource, $facets));
 * ```
 *
 * A `filterable: true` child mounted as a relative **ignores the bound parent entirely** and lists every
 * row in the table at the nested URL, behind a 200. Nothing throws, nothing logs, and the route looks
 * correct in `route:list`.
 *
 * This is measured, not theorised. `splicewire/laravel-beam-media` carries the workaround in its own
 * declaration (`MediaData.php:50-60`), setting `filterable: false` and saying why: *"the only setting
 * under which the relative index scopes correctly."* That comment is the estate's entire defence — one
 * package author who happened to find it. **The estate is safe today by sequencing luck**: it has exactly
 * one live edge and that edge opted out by hand. The next edge declared against a filterable resource
 * leaks, and the person declaring it has no reason to suspect the interaction exists.
 *
 * ## Advisory, not fatal — and the reason is the estate's standing rule
 *
 * Whether a child resource is filterable, and whether it is *also* reachable as a relative, are facts
 * about the **host**: the two declarations may live in two packages that never name each other, which is
 * precisely the tier arrangement `ParticleRelative`'s docblock defends. An author could not have gotten
 * this right without knowing which host would load both. AGENTS.md's rule applies — *"a check whose
 * answer depends on the host must not throw"* — and `EventCatalogPrefixAudit` is the instance that took a
 * host off the air by getting it backwards.
 *
 * ## What it does NOT check, deliberately
 *
 * **It does not check that the parent is authorized.** It is not: the relative branch resolves the bound
 * parent through `Router::model()` — a `findOrFail`, never an authorization — while `ParticleController`'s
 * own comments claim *"the (already-authorized) parent"* (`:109`, `:470`). Measured 2026-08-27 against the
 * live edge: a non-owner for whom `Gate::allows('view', $parent)` is `false` receives **HTTP 200**.
 *
 * That gap is real, but it is **not a conformance defect an audit can name** — there is no slot on
 * `#[ParticleRelative]` for the ability it should check, so there is nothing for a host to fix in response
 * to a finding. Reporting it here would produce a warning with no closing move, which is the shape this
 * estate has learned to refuse. It is a design question, and it is
 * particle-operation-surface ticket 16's.
 *
 * @see ParticleRelative the declaration this walks
 */
class RelativeEdgeIntegrityAudit implements DoctorAudit
{
    public const CHECK = 'particle.relative-edge';

    public function __construct(
        private ParticleRelativeRegistry $relatives,
        private ParticleResourceRegistry $resources,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $edges = $this->relatives->all();

        if ($edges === []) {
            return [Finding::pass(self::CHECK, 'No relative edge is declared on this host.')];
        }

        $rows = [];

        foreach ($edges as $edge) {
            foreach ($this->problems($edge) as $problem) {
                $rows[] = $problem;
            }
        }

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d declared relative edge%s; every one scopes its child through the bound parent.',
                count($edges),
                count($edges) === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d relative edge problem%s: %s',
            count($rows),
            count($rows) === 1 ? '' : 's',
            implode('; ', $rows),
        ))];
    }

    /** @return list<string> */
    private function problems(ParticleRelative $edge): array
    {
        $rows = [];
        $key = $edge->key();

        // The `model:` is what gets route-model-bound. A non-Eloquent class cannot be, and the edge is
        // honestly Eloquent-only (07 D5 refused a `RelatesRecords` port for it: zero non-Eloquent parents
        // with edges exist, and a port with no implementation is what ticket 06 dissolved 1,120 lines to
        // remove). Named here so the refusal is discoverable rather than silent.
        if (! is_subclass_of($edge->model, Model::class)) {
            $rows[] = sprintf(
                'edge [%s] declares `model: %s`, which is not an Eloquent model — the relative mount '
                    .'route-model-binds the parent, so a non-Eloquent parent cannot be expressed. The edge '
                    .'mechanism is Eloquent-only by construction',
                $key,
                $edge->model,
            );
        }

        $child = $this->resources->find($edge->child);

        // A host fact, and the honest answer is "report it": the child may be registered by a package this
        // host does not install, in which case the edge is inert rather than wrong.
        if ($child === null) {
            $rows[] = sprintf(
                'edge [%s] mounts child resource [%s], which is not registered on this host — the mount '
                    .'has nothing to expose',
                $key,
                $edge->child,
            );

            return $rows;
        }

        if ($child->filterable) {
            $rows[] = sprintf(
                'edge [%s] mounts child resource [%s], which declares `filterable: true` — '
                    .'`ParticleController::index()` rides the data-filters builder for a filterable resource '
                    .'and DISCARDS the bound-parent query, so this edge lists EVERY [%s] row at the nested '
                    .'URL instead of the parent\'s, behind a 200. Set `filterable: false` on [%s] (what '
                    .'`beam-media` does, and says why), or do not mount it as a relative',
                $key,
                $edge->child,
                $edge->child,
                $edge->child,
            );
        }

        return $rows;
    }
}
