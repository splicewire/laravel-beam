<?php

namespace Splicewire\Beam\Scribe\OpenApi;

use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Surface\ApiGroup;
use Splicewire\Beam\Surface\GroupRegistry;

/**
 * Project the {@see GroupRegistry} tree into the document as `x-tagGroups` — the second sidebar level
 * (api-surface-coherence ticket 02).
 *
 * Ticket 01 made the taxonomy arbitrarily deep and ticket 17 shipped it, but nothing rendered the depth:
 * `GroupStrategy` emits a flat `groupName` per route, so the parents were declared and invisible. This is
 * the writer-side half — a document-assembly hook on Scribe's `openapi.generators` slot, not a patch to
 * Scribe's `OpenAPISpecWriter` and not a post-generation rewrite of the artifact.
 *
 * ## The rendering ceiling is TWO, and it was measured, not assumed
 *
 * Ticket 02 rendered four probe documents through the same pinned-by-nothing Scalar bundle the docs page
 * loads, and looked:
 *
 *   - `x-tagGroups` renders one extra level. Confirmed.
 *   - OpenAPI 3.2's native `tags[].parent` renders NOTHING — not in a 3.1 document, and not in a 3.2 one
 *     either. Scalar parses 3.2 (the version badge reads it back) and still draws a flat sidebar. The
 *     native nesting is specified and unrendered; `x-tagGroups` is the only thing that works today.
 *   - The two coexist: a document carrying both renders the groups and ignores the parents.
 *
 * So depth beyond two has nowhere to go, and this generator **flattens rather than drops**: a group is
 * bucketed under its ROOT ancestor ({@see GroupRegistry::root()}), never its immediate parent. A
 * depth-three leaf renders beside its grandparent's other leaves instead of vanishing. The tree stays as
 * deep as a host wants to declare it; only the rendering is capped.
 *
 * ## Totality is not a nicety here — an omitted tag is DELETED
 *
 * The probe's load-bearing finding: a tag that appears in no `x-tagGroups` entry is dropped from the
 * rendered reference **entirely** — sidebar and content both. Its operations do not fall back to an
 * ungrouped section; they are simply not in the document the reader sees. A partial `x-tagGroups` is
 * therefore worse than none at all, which is why every tag is bucketed unconditionally and anything the
 * registry cannot resolve lands in {@see UNGROUPED} rather than being skipped. A host test asserts that
 * bucket is empty; if it ever fills, the endpoints are still visible under a plainly-wrong heading, which
 * is the failure mode to prefer.
 *
 * ## What a root may not be
 *
 * A root that also carries operations renders as `Knowledge › Knowledge` — the exact shape ticket 17
 * renamed `Platform`'s same-named leaf to `Discovery` to avoid. It is safe (nothing is dropped; that was
 * probed too) and it is ugly, so it is a host convention rather than an exception here.
 *
 * ## What this cannot carry
 *
 * An `x-tagGroups` entry is `{name, tags}` — there is no description field. The eight root descriptions
 * declared in the host's taxonomy have no home in this shape and do not render. Only leaf tags keep their
 * prose, via the ordinary `tags[].description`.
 */
class TagGroupGenerator extends OpenApiGenerator
{
    /** Where a tag the registry cannot resolve goes, because omitting it would delete it. */
    public const UNGROUPED = 'Other';

    public function root(array $root, array $groupedEndpoints): array
    {
        $tags = $root['tags'] ?? [];

        if ($tags === []) {
            return $root;
        }

        $registry = app(GroupRegistry::class);

        // rootKey => ['group' => ApiGroup|null, 'members' => [sortKey => tagName]]
        $buckets = [];

        foreach ($tags as $tag) {
            $name = $tag['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $group = $registry->resolve($name);

            if ($group === null) {
                $buckets[static::UNGROUPED]['group'] ??= null;
                $buckets[static::UNGROUPED]['members'][sprintf('~%s', $name)] = $name;

                continue;
            }

            $rootGroup = $registry->root($group->key) ?? $group;

            $buckets[$rootGroup->key]['group'] = $rootGroup;
            $buckets[$rootGroup->key]['members'][$this->sortKey($registry, $group)] = $name;
        }

        // Roots in declared order; the ungrouped bucket last, whatever its name sorts as.
        uasort($buckets, function (array $a, array $b): int {
            $left = $a['group'] ?? null;
            $right = $b['group'] ?? null;

            if ($left === null || $right === null) {
                return ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
            }

            return $left->order <=> $right->order;
        });

        $groups = [];

        foreach ($buckets as $key => $bucket) {
            $members = $bucket['members'];
            ksort($members);

            $groups[] = [
                'name' => $bucket['group']?->name ?? $key,
                'tags' => array_values($members),
            ];
        }

        $root['x-tagGroups'] = $groups;

        return $root;
    }

    /**
     * Order a member within its bucket by its position in the tree, not its name.
     *
     * The key is the chain of `order` values from the root down to the group itself, so a leaf sorts
     * immediately after the leaf it is declared under even though the rendering has flattened them onto
     * one level — the only place the third level survives at all is this ordering.
     */
    protected function sortKey(GroupRegistry $registry, ApiGroup $group): string
    {
        $chain = array_reverse($registry->ancestry($group->key));

        $orders = array_map(
            static fn (ApiGroup $node): string => sprintf('%06d.%s', $node->order, $node->key),
            $chain,
        );

        return implode('/', $orders);
    }
}
