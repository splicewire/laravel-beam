<?php

declare(strict_types=1);

namespace Splicewire\Beam\Realm\Attributes;

use Attribute;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The operator-console realm preset (realm-architecture ticket 08 slice D): the central, never-tenanted,
 * root-guarded realm mounted under `/admin`. A host declares one by placing `#[AdminRealm]` on a
 * realm-marker class; the discovery scan self-registers it onto the {@see RealmRegistry},
 * overriding the imperative base `admin` realm last-wins if the host wants a differently-shaped operator
 * realm.
 *
 * Presets: `central: true`, `guard: 'root'`, `routeBase: '/admin'` — the operator defaults. Any may be
 * overridden per declaration.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AdminRealm extends Realm
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        string $key = 'admin',
        string $routeBase = '/admin',
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
