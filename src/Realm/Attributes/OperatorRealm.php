<?php

namespace Splicewire\Beam\Realm\Attributes;

use Attribute;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The operator-console realm preset (realm-architecture ticket 08 slice D): the central, never-tenanted,
 * root-guarded realm mounted under `/operator`. A host declares one by placing `#[OperatorRealm]` on a
 * realm-marker class; the discovery scan self-registers it onto the {@see RealmRegistry},
 * overriding the imperative base `operator` realm last-wins if the host wants a differently-shaped
 * operator realm.
 *
 * Presets: `central: true`, `guard: 'root'`, `routeBase: '/operator'` — the operator defaults. Any may
 * be overridden per declaration.
 *
 * Renamed from `AdminRealm` (ADR-0156, 2026-08-07 addendum) — the wire key/route now follow the concept.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class OperatorRealm extends Realm
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        string $key = 'operator',
        string $routeBase = '/operator',
        ?string $guard = 'root',
        bool $central = true,
        array $stack = [],
    ) {
        parent::__construct(
            key: $key,
            routeBase: $routeBase,
            guard: $guard,
            central: $central,
            stack: $stack,
        );
    }
}
