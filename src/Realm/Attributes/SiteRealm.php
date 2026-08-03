<?php

namespace Splicewire\Beam\Realm\Attributes;

use Attribute;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The PUBLIC content realm preset (ADR-0165, beamux-entry-charter S3): the root of the public site's
 * `BeamUxEntry` containment tree, mounted at the domain root `/` and **unguarded by default**. A host
 * declares one by placing `#[SiteRealm]` on a realm-marker class; the discovery scan self-registers it
 * onto the {@see RealmRegistry}, overriding the imperative base `site` realm last-wins if the host wants
 * a differently-shaped public realm (e.g. a guarded preview site).
 *
 * Presets: `guard: null` (unguarded — public content is not an authenticated surface), `central: false`,
 * `routeBase: '/'`. Any may be overridden per declaration.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class SiteRealm extends Realm
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        string $key = 'site',
        string $routeBase = '/',
        ?string $guard = null,
        bool $central = false,
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
