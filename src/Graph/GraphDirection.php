<?php

namespace Splicewire\Beam\Graph;

use Rushing\Graphine\Enums\TraversalDirection;

/**
 * The direction a neighbours traversal runs, on the wire.
 *
 * A beam-owned enum rather than graphine's own {@see TraversalDirection} directly, so the wire shape
 * is beam's to keep stable even if graphine's internal enum moves; {@see toTraversalDirection()} is the
 * one place the two are tied, by equal string value (ticket 01, scope 3: "a direction enum maps onto
 * graphine's TraversalDirection").
 */
enum GraphDirection: string
{
    case Descendants = 'descendants';
    case Ancestors = 'ancestors';
    case Both = 'both';

    /** The graphine traversal direction this wire value maps onto (equal string value). */
    public function toTraversalDirection(): TraversalDirection
    {
        return TraversalDirection::from($this->value);
    }
}
