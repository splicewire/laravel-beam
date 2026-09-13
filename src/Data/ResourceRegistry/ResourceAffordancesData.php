<?php

namespace Splicewire\Beam\Data\ResourceRegistry;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\ResourceRegistryRow;

/**
 * The EFFECTIVE affordances of a resource — backing capability ∩ declared intent, per verb. Computed by
 * {@see ResourceRegistryRow::affordances()}, which carries the rule for each verb.
 *
 * ⚠️ A surface reads these to decide what to OFFER. They are not an authorization answer: the transport's
 * own gates (realm membership, the resource's read posture, the write gate, the handler's 405s) still
 * decide every request, and a client that skipped this object would be refused exactly where it is.
 */
#[TypeScript]
class ResourceAffordancesData extends BeamData
{
    public function __construct(
        public bool $list,
        public bool $show,
        public bool $create,
        public bool $edit,
        public bool $delete,
        public bool $filter,
    ) {}
}
