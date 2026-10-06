<?php

namespace Splicewire\Beam\Ia;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One realm a principal may cross to: its M1 label, its home href, its surface and its gate result. app-walkthrough
 * APP-14 (M2′) adds `manifest`, the realm whose manifest it reads (the registry's effective realm, so a realm stacked on
 * `tenant` reads the tenant's), and `tenantScoped`, whether that realm resolves a tenant. A shell picks its rail and its
 * tenant chrome from these instead of from hand realm exports.
 */
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
        public readonly string $manifest,
        public readonly bool $tenantScoped,
    ) {}
}
