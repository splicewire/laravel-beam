<?php

namespace Splicewire\Beam\Surface;

/**
 * One entry in the {@see GroupRegistry} — a node of the host's API taxonomy.
 *
 * **One taxonomy, two renderings** (api-surface-coherence ticket 01, Q2/Q5). This is NOT a "doc group"
 * beside a separate "nav group": nav and docs were never disagreeing, they were cutting the same tree at
 * different depths — nav's `Knowledge` is the parent of what the reference splits into `Fragments`,
 * `Taxonomy`, `Knowledge Graph`, `Search & Retrieval`. So there is one `name`, plus an optional
 * display-only {@see $navLabel} for the places a human-facing menu wants a shorter word than the
 * reference's tag ("Graph" for `Knowledge Graph`). The precedent is `$label`/`$singularLabel` on
 * `ParticleResource`: a display alias carries no taxonomy semantics.
 *
 * **Depth lives here, not on the resource.** {@see $parent} is a key of another registered group, so the
 * tree is arbitrarily deep and a leaf moves with a one-line edit. `ParticleResource::$section` — which
 * was the old, implicit second level — collapses into this.
 *
 * The {@see $description} is public API copy: it becomes the OpenAPI tag description. Declaring it beside
 * the group is the point — Scribe otherwise scavenges a group's description from whichever member
 * endpoint happens to carry prose after its `@group` line, so a group inherits an arbitrary member's copy.
 */
class ApiGroup
{
    /**
     * @param  string  $key  stable identity, kebab-case (`knowledge-graph`); what {@see $parent} points at
     * @param  string  $name  the canonical human name — the OpenAPI tag
     * @param  string  $description  the OpenAPI tag description; public API copy, not a placeholder
     * @param  string|null  $parent  key of the parent group; null makes this a root
     * @param  string|null  $navLabel  display-only shorter name for menus; NO taxonomy semantics
     * @param  int  $order  sibling ordering hint for renderers; ties keep registration order
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description = '',
        public ?string $parent = null,
        public ?string $navLabel = null,
        public int $order = 0,
    ) {}

    /** The name a menu shows — {@see $navLabel} when declared, the canonical {@see $name} otherwise. */
    public function label(): string
    {
        return $this->navLabel ?? $this->name;
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }
}
