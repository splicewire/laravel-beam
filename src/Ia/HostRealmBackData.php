<?php

namespace Splicewire\Beam\Ia;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** "← Back to {workspace}" (IA-4): the workspace realm's label and home. */
#[TypeScript]
final class HostRealmBackData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly string $href,
    ) {}
}
