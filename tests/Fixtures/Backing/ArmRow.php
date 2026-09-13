<?php

namespace Splicewire\Beam\Tests\Fixtures\Backing;

/**
 * One row of an in-memory composite arm — deliberately a PLAIN object rather than a `Data`, so the
 * Frame transport test exercises `ParticleFrameResourceHandler::streamedIndex()`'s projection step
 * (`$dataClass::from($item)`) rather than its already-a-Data shortcut.
 */
class ArmRow
{
    public function __construct(
        public string $id,
        public string $source,
        public string $label,
        public ?string $at = null,
    ) {}
}
