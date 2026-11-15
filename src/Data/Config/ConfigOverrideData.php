<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Enums\ConfigOverrideOp;

/**
 * One pointer of a section's sparse override set at the operator tier, as the DRAFT holds it (ADR-0245 decision 2;
 * config-sections ticket 05; the PRD's "sparse overrides").
 *
 * - `op`: `set`, or `null` for a retained null override. An `unset` pointer is simply absent (it inherits).
 * - `value`: the value a `set` writes; null for an explicit null, and ALWAYS null for a secret (ADR-0245 §6).
 * - `secret`, `set`, `setAt`: a secret says only whether it has a value and when it was written.
 * - `revision`: the store's revision id this pointer currently names (the address a version or a Release member keeps).
 */
#[TypeScript]
class ConfigOverrideData extends BeamData
{
    public function __construct(
        public string $pointer,
        public ConfigOverrideOp $op,
        public mixed $value,
        public bool $secret,
        public bool $set,
        public ?string $setAt,
        public string $revision,
    ) {}
}
