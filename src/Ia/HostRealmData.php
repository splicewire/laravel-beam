<?php

namespace Splicewire\Beam\Ia;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One realm a principal may cross to: its M1 label, its home href, its surface and its gate result. */
#[TypeScript]
final class HostRealmData extends Data
{
    /** @param  array<string, mixed>|null  $upsell */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $href,
        public readonly string $surface,
        public readonly bool $locked,
        public readonly ?array $upsell,
    ) {}
}
