<?php

namespace Splicewire\Beam\Surface;

use Illuminate\Support\Str;
use RuntimeException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The host's API taxonomy, and the chain that resolves a route into it (api-surface-coherence ticket 01).
 *
 * **Seam:** singleton-accumulator — a package or the host calls {@see register()} from its own provider.
 * **Arity:** pick-one — a resolution engages exactly one entry, most-specific rung first.
 *
 * ## What this replaced
 *
 * A route's group used to be a URI-prefix GUESS, twice over: a host-owned glob map matched
 * `api/v1/fragments/*` and won, and anything it missed fell to `Str::headline()` of the first meaningful
 * URI segment. Both are string-parsing a URL for a fact the route already carries — every particle route
 * is stamped with its resource key at mount time, and nothing read it. The visible cost was that a
 * sub-operation OF a resource documented itself under whatever its URL happened to start with:
 * `GET /fragments/{fragment}/media` filed under Fragments, `POST /context-scopes/{id}/op/embeddings`
 * swallowed by whichever glob reached it first.
 *
 * So: **the group is a property of the RESOURCE, not of the URI.**
 *
 * ## The resolution chain — {@see resolveRoute()}, first match wins
 *
 *   1. **Host override by resource key** ({@see assign()}) — the host names only what it disagrees with.
 *   2. **The resource's own declaration** — `ParticleResource::$group`, read through {@see resolve()} so a
 *      package may declare either a key, the canonical name, or a `navLabel`. A package declaration is a
 *      DEFAULT, never authoritative: shipping one host's taxonomy inside a package is the mistake that
 *      filed three commerce controllers under "Studio" long after their URIs moved.
 *   3. **Host URI globs** ({@see matchUri()}) — the shrinking backlog for the routes that carry no
 *      resource stamp at all. This rung is a migration artifact with a ratchet test on its size, not a
 *      design; every route that gains a resource declaration leaves it.
 *   4. **URI-segment headline** — the last-resort guess, returning an UNREGISTERED group so the
 *      difference between "declared" and "guessed" stays visible to a coverage test.
 *
 * ## What beam-core ships
 *
 * The registry and the chain — and no groups. A taxonomy is the host's; seeding this estate's roots from
 * beam would ship one host's engine names to every beam site, which is exactly the failure the glob map's
 * own header warned about. A fresh beam host therefore groups its own reference correctly off its
 * declared particle resources, with no taxonomy config at all.
 */
#[IsRegistry(
    root: 'beam.surface.groups',
    of: 'the API taxonomy — the group tree (key/name/description/parent) plus the chain that resolves a route into it',
    arity: RegistryArity::PickOne,
    entryType: ApiGroup::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'A PRECEDENCE registry — registry-kernel ticket 15 archetype (d), explicitly never sweepable. '
        .'The groups are one keyspace; `$assignments` (rung 1) and `$globs` (rung 3, ordered first-match) '
        .'are a resolution LADDER over them, not more entries. Only the tree is declared here; how the '
        .'ladder is expressed is ticket 36\'s.',
    order: 12,
)]
class GroupRegistry
{
    /** @var array<string, ApiGroup> keyed by group key */
    private array $groups = [];

    /** @var array<string, string> resource key => group key (rung 1) */
    private array $assignments = [];

    /** @var list<array{pattern: string, group: string}> ordered, first match wins (rung 3) */
    private array $globs = [];

    /** URI segments that are never the resource collection — stripped before the rung-4 guess. */
    protected array $noiseSegments = ['api', 'splice', 'beam', 'stud'];

    public function __construct(protected ?ParticleResourceRegistry $resources = null) {}

    /**
     * Declare one or more groups. Idempotent per key — re-registering replaces, so a provider that boots
     * twice (test harness) doesn't double-declare.
     */
    public function register(ApiGroup ...$groups): static
    {
        foreach ($groups as $group) {
            $this->groups[$group->key] = $group;
        }

        return $this;
    }

    /** Rung 1 — the host overrides a resource's declared group by resource key. */
    public function assign(string $resourceKey, string $groupKey): static
    {
        $this->assignments[$resourceKey] = $groupKey;

        return $this;
    }

    /**
     * Rung 3 — map a URI glob onto a group, for routes carrying no resource declaration. Ordered: first
     * match wins, so a narrow pattern must be declared before the broad one it carves out of.
     *
     * Every entry here is backlog. The honest fix for any given route is a resource declaration
     * (`->beam()->inResource(...)`), which retires its glob.
     *
     * @param  string|list<string>  $patterns  {@see Str::is} globs against the full route URI; `*` crosses slashes
     */
    public function matchUri(string|array $patterns, string $groupKey): static
    {
        foreach ((array) $patterns as $pattern) {
            $this->globs[] = ['pattern' => $pattern, 'group' => $groupKey];
        }

        return $this;
    }

    /** @return array<string, ApiGroup> */
    public function all(): array
    {
        return $this->groups;
    }

    public function has(string $key): bool
    {
        return isset($this->groups[$key]);
    }

    public function get(string $key): ?ApiGroup
    {
        return $this->groups[$key] ?? null;
    }

    /**
     * Resolve a loosely-spelled token — a key, the canonical name, a `navLabel`, or a slug of any of them
     * — to a registered group.
     *
     * The looseness is deliberate and load-bearing for rung 2: a package declares `group: 'Graph'` for its
     * own menu while the reference's tag is `Knowledge Graph`. Those are one group with a display alias,
     * not two taxonomies, and this method is where that claim is cashed. An unresolvable token is a MISS
     * (null), not an error — a package default the host has not adopted simply falls through to the next
     * rung.
     */
    public function resolve(string $token): ?ApiGroup
    {
        if (isset($this->groups[$token])) {
            return $this->groups[$token];
        }

        $slug = Str::slug($token);

        foreach ($this->groups as $group) {
            if ($group->name === $token || $group->navLabel === $token) {
                return $group;
            }
        }

        foreach ($this->groups as $group) {
            if (Str::slug($group->name) === $slug || ($group->navLabel !== null && Str::slug($group->navLabel) === $slug)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The group a resource belongs to — rung 1 then rung 2. Null when the resource neither is overridden
     * nor declares a group this registry knows.
     */
    public function forResource(string $resourceKey): ?ApiGroup
    {
        if (isset($this->assignments[$resourceKey])) {
            return $this->get($this->assignments[$resourceKey])
                ?? throw new RuntimeException(
                    "Resource [{$resourceKey}] is assigned to group [{$this->assignments[$resourceKey]}], which is not registered."
                );
        }

        $declared = $this->resources?->has($resourceKey)
            ? $this->resources->get($resourceKey)->group
            : null;

        return is_string($declared) && $declared !== '' ? $this->resolve($declared) : null;
    }

    /** Rung 3 — the first declared glob matching this URI. */
    public function forUri(string $uri): ?ApiGroup
    {
        $uri = trim($uri, '/');

        foreach ($this->globs as $glob) {
            if (Str::is($glob['pattern'], $uri)) {
                return $this->get($glob['group'])
                    ?? throw new RuntimeException(
                        "URI pattern [{$glob['pattern']}] maps to group [{$glob['group']}], which is not registered."
                    );
            }
        }

        return null;
    }

    /**
     * The whole chain. `$resourceKey` is what the route declares (read it with
     * `BeamRouteAction::resourceKey()`); null for a route that declares nothing.
     *
     * Never returns null: rung 4 always produces something. Use {@see has()} on the returned key — or
     * {@see isDeclared()} — to tell a declared group from a guessed one.
     */
    public function resolveRoute(?string $resourceKey, string $uri): ApiGroup
    {
        if ($resourceKey !== null && ($group = $this->forResource($resourceKey)) !== null) {
            return $group;
        }

        if (($group = $this->forUri($uri)) !== null) {
            return $group;
        }

        return $this->guessFromUri($uri);
    }

    /** Whether this group came off a declaration rather than the rung-4 guess. */
    public function isDeclared(ApiGroup $group): bool
    {
        return isset($this->groups[$group->key]);
    }

    /**
     * Rung 4 — `Str::headline()` of the resource-collection URI segment, as an unregistered group.
     *
     * `headline` and not `studly`: a studly group can never match a hand-written multi-word name, so one
     * resource split into two tags no consumer could reconcile (`ContextScopes` beside `Context Scopes`).
     */
    public function guessFromUri(string $uri): ApiGroup
    {
        $segment = $this->resourceSegment($uri) ?? 'endpoints';

        return new ApiGroup(key: Str::slug($segment), name: Str::headline($segment));
    }

    /** The chain of groups from `$key` up to its root, nearest first. */
    public function ancestry(string $key): array
    {
        $chain = [];
        $seen = [];

        while (($group = $this->get($key)) !== null) {
            if (isset($seen[$key])) {
                throw new RuntimeException("Group [{$key}] is its own ancestor — the taxonomy has a cycle.");
            }

            $seen[$key] = true;
            $chain[] = $group;

            if ($group->parent === null) {
                return $chain;
            }

            if (! $this->has($group->parent)) {
                throw new RuntimeException("Group [{$group->key}] declares parent [{$group->parent}], which is not registered.");
            }

            $key = $group->parent;
        }

        return $chain;
    }

    /** The root ancestor of a group — what Frame's nav section derives from. */
    public function root(string $key): ?ApiGroup
    {
        $chain = $this->ancestry($key);

        return $chain === [] ? null : $chain[array_key_last($chain)];
    }

    /** @return list<ApiGroup> the roots, in declared order */
    public function roots(): array
    {
        return array_values(array_filter($this->groups, static fn (ApiGroup $g): bool => $g->isRoot()));
    }

    /** @return list<ApiGroup> the direct children of a group, order-then-registration */
    public function children(string $key): array
    {
        $children = array_values(array_filter($this->groups, static fn (ApiGroup $g): bool => $g->parent === $key));
        usort($children, static fn (ApiGroup $a, ApiGroup $b): int => $a->order <=> $b->order);

        return $children;
    }

    /**
     * How many URI globs the rung-3 backlog still carries. The host ratchets this: it may shrink as
     * routes gain resource declarations, never grow.
     */
    public function globCount(): int
    {
        return count($this->globs);
    }

    /** @return list<array{pattern: string, group: string}> */
    public function globs(): array
    {
        return $this->globs;
    }

    /**
     * Assert the taxonomy is well-formed — every parent, assignment target and glob target registered, and
     * no cycles. Throws on the first problem; a host boot-time test calls this.
     */
    public function validate(): void
    {
        foreach ($this->groups as $group) {
            $this->ancestry($group->key);
        }

        foreach ($this->assignments as $resourceKey => $groupKey) {
            if (! $this->has($groupKey)) {
                throw new RuntimeException("Resource [{$resourceKey}] is assigned to unregistered group [{$groupKey}].");
            }
        }

        foreach ($this->globs as $glob) {
            if (! $this->has($glob['group'])) {
                throw new RuntimeException("URI pattern [{$glob['pattern']}] maps to unregistered group [{$glob['group']}].");
            }
        }
    }

    /**
     * The resource-collection segment: the first meaningful URI segment after dropping the `api` root, a
     * `v{n}` version, any tier noise prefix, and `{param}` placeholders.
     */
    protected function resourceSegment(string $uri): ?string
    {
        foreach (explode('/', trim($uri, '/')) as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }

            if (in_array($segment, $this->noiseSegments, true) || preg_match('/^v\d+$/', $segment)) {
                continue;
            }

            return $segment;
        }

        return null;
    }
}
