<?php

namespace Splicewire\Beam\Ia;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ux-walkthrough M2: everything realm-shaped a renderer reads, per principal and per path. The switcher entries,
 * the current realm and the Back target. It replaces {@see \Splicewire\Beam\Realm\RealmManifestProjector}'s plain
 * array at the boundary. Inertia shares it as `realms`; the SPA reads it per request. Nothing realm-shaped goes in
 * runtime config.
 */
#[TypeScript]
final class HostRealmsData extends Data
{
    /**
     * @param  list<HostRealmData>  $realms
     * @param  list<string>  $plays  the cross-instance sides this host plays (Side values, e.g. `hub`,
     *                               `client`), so a renderer decides host-true chrome at the mount —
     *                               a package surface renders only where it is true (APP-11/APP-24, IA-6).
     */
    public function __construct(
        #[DataCollectionOf(HostRealmData::class)]
        public readonly array $realms,
        public readonly ?string $current,
        public readonly ?HostRealmBackData $back,
        public readonly array $plays = [],
    ) {}
}
