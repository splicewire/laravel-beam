<?php

declare(strict_types=1);

namespace Splicewire\Beam\Realm\Attributes;

use Attribute;

/**
 * The account/identity realm preset (realm-architecture ticket 08 slice D): the non-central realm of the
 * authenticated user across workspaces, mounted at `/settings` with the shell's own auth. It COMPOSES
 * THROUGH other realms via its `stack` (slice E) — a single-tenant install declares `stack: ['tenant']`
 * so a user's Settings resolve within their workspace; a cross-workspace SaaS empties the stack so `user`
 * sources its own account manifest.
 *
 * Presets: `central: false`, `routeBase: '/settings'`, `guard: null`, `stack: []`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UserRealm extends Realm
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        string $key = 'user',
        string $routeBase = '/settings',
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
