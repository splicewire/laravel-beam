<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

/** Fixture (config-sections 20): `up` is `?parent`, resolved against the declaring class's parent, {@see TreeBaseNode}. */
class TreeChildNode extends TreeBaseNode
{
    public function __construct(
        string $name,
        ?string $pin,
        ?TreeBaseNode $child,
        public ?parent $up,
    ) {
        parent::__construct($name, $pin, $child);
    }
}
