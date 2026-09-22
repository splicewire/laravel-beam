<?php

namespace Splicewire\Beam\Data\ResourceRegistry;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * What a resource's DECLARATION says it may do — the affordance flags as the frame manifest resolves them
 * (a null `editable`/`deletable` follows the create gate), plus the declared `policy:` ability.
 *
 * Intent only narrows capability; {@see ResourceAffordancesData} is the intersection a surface acts on.
 */
#[TypeScript]
class ResourceIntentData extends BeamData
{
    public function __construct(
        public bool $readOnly,
        public bool $creatable,
        public bool $editable,
        public bool $deletable,
        public bool $showable,
        /** The declared `policy:` — a Gate ability, a policy class-string, or null when undeclared. */
        public ?string $policy,
    ) {}
}
