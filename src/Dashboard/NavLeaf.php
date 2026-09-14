<?php

namespace Splicewire\Beam\Dashboard;

/**
 * One destination of a realm's rail: a nav node with an href and no children, plus its position in the
 * rail's walk order. What a dashboard tile is drawn from, and what the participation rule matches a
 * resource against.
 */
final class NavLeaf
{
    public function __construct(
        /** The zero-based position in a depth-first walk of the rail — the order the rail renders it. */
        public readonly int $index,
        public readonly string $href,
        public readonly ?string $routeName,
        public readonly string $title,
        public readonly ?string $icon,
    ) {}
}
