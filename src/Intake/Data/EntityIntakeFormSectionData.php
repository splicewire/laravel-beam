<?php

namespace Splicewire\Beam\Intake\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** One authorized section, with references resolved before it crosses the guest boundary. */
#[TypeScript]
class EntityIntakeFormSectionData extends BeamData
{
    public function __construct(
        public string $key,
        /** @var array<string, mixed> */
        public array $schema,
        /** @var array<string, mixed> */
        public array $fields,
    ) {}
}
