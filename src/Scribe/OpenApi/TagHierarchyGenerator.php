<?php

namespace Splicewire\Beam\Scribe\OpenApi;

use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Surface\ApiGroup;
use Splicewire\Beam\Surface\GroupRegistry;

/**
 * Project the {@see GroupRegistry} tree into the document's `tags` as an OpenAPI 3.2 hierarchy
 * (api-surface-coherence ticket 02).
 *
 * Ticket 01 made the taxonomy arbitrarily deep and ticket 17 shipped it, but nothing rendered the
 * depth: `GroupStrategy` emits a flat `groupName` per route, so the parents were declared and
 * invisible. This is the writer-side half — a document-assembly hook on Scribe's
 * `openapi.generators` slot, not a patch to Scribe's `OpenAPISpecWriter` and not a post-generation
 * rewrite of the artifact.
 *
 * ## Why `parent` and not `x-tagGroups`
 *
 * Both were probed against the renderer rather than assumed. `x-tagGroups` (Redoc/Scalar) buys
 * exactly ONE extra level — a group may contain tags, never another group — and it has a failure mode
 * that is easy to miss and expensive to hit: **a tag named in no group is dropped from the rendered
 * reference entirely**, sidebar and content, taking its operations with it. A partial `x-tagGroups`
 * silently deletes endpoints from published docs while the document still contains them.
 *
 * OpenAPI 3.2's `tags[].parent` has neither limit: arbitrary depth, and a renderer that does not
 * understand it degrades to a FLAT list rather than a lossy one. That is the better failure, so this
 * emits `parent` alone and `x-tagGroups` is not written at all.
 *
 * The cost, stated plainly: as of this writing no released renderer draws `parent` — it is specified,
 * parsed and ignored. This host renders it because it ships a patched Scalar
 * (`patches/@scalar+workspace-store+*.patch`); every other consumer of the document sees a flat but
 * complete tag list until upstream catches up.
 *
 * ## Ancestors become real tags
 *
 * 3.2 requires a `parent` to name a tag the document declares, so every ancestor of a used group is
 * emitted as a tag whether or not any operation carries it. That is a gain, not a chore: the roots'
 * descriptions are public API copy that the `x-tagGroups` shape had no field for and could not render.
 *
 * Tags are emitted in TREE order — each root followed by its subtree, siblings by declared `order` —
 * so the reference reads in the order the taxonomy declares rather than alphabetically.
 */
class TagHierarchyGenerator extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        $tags = $root['tags'] ?? [];

        if ($tags === []) {
            return $root;
        }

        $registry = app(GroupRegistry::class);

        // Tag name => the tag entry Scribe already built (carries the description and any x-keys).
        $existing = [];
        // Group key => the ApiGroup it resolves to, for every group reachable from a used tag.
        $needed = [];
        // Tags the registry cannot resolve. Emitted last, parentless — never dropped.
        $unresolved = [];

        foreach ($tags as $tag) {
            $name = $tag['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $existing[$name] = $tag;

            $group = $registry->resolve($name);

            if ($group === null) {
                $unresolved[$name] = $tag;

                continue;
            }

            // The whole ancestry, so a parent is always a declared tag.
            foreach ($registry->ancestry($group->key) as $ancestor) {
                $needed[$ancestor->key] = $ancestor;
            }
        }

        $emitted = [];

        foreach ($this->treeOrder($registry, $needed) as $group) {
            $tag = $existing[$group->name] ?? ['name' => $group->name, 'description' => $group->description];

            // An ancestor synthesised here has no endpoints and so no Scribe-built description.
            if (trim($tag['description'] ?? '') === '') {
                $tag['description'] = $group->description;
            }

            if ($group->parent !== null && isset($needed[$group->parent])) {
                $tag['parent'] = $needed[$group->parent]->name;
            }

            $emitted[] = $tag;
        }

        foreach ($unresolved as $tag) {
            $emitted[] = $tag;
        }

        $root['tags'] = $emitted;

        return $root;
    }

    /**
     * The needed groups, depth-first: each root, then its subtree, siblings ordered by `order`.
     *
     * Emission order is the document's tag order, which is the order the reference renders — so the
     * tree's declared shape is also its reading order, with no sorter to configure.
     *
     * @param  array<string, ApiGroup>  $needed
     * @return list<ApiGroup>
     */
    protected function treeOrder(GroupRegistry $registry, array $needed): array
    {
        $roots = array_values(array_filter($needed, static fn (ApiGroup $group): bool => $group->isRoot()));
        usort($roots, static fn (ApiGroup $a, ApiGroup $b): int => $a->order <=> $b->order);

        $ordered = [];

        foreach ($roots as $rootGroup) {
            $this->appendSubtree($registry, $rootGroup, $needed, $ordered);
        }

        return $ordered;
    }

    /**
     * @param  array<string, ApiGroup>  $needed
     * @param  list<ApiGroup>  $ordered
     */
    protected function appendSubtree(GroupRegistry $registry, ApiGroup $group, array $needed, array &$ordered): void
    {
        $ordered[] = $group;

        foreach ($registry->children($group->key) as $child) {
            if (isset($needed[$child->key])) {
                $this->appendSubtree($registry, $child, $needed, $ordered);
            }
        }
    }
}
