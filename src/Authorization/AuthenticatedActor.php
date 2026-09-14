<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The {@see ActorPort}'s answer narrowed to what a visibility question can use.
 *
 * The port answers `mixed` on purpose — a transport may hand over a tenant or a credential principal
 * that the entitlement plane resolves on its own — but {@see ResourceVisibility::listable()} asks about
 * an {@see Authenticatable}. Every backing that filters its rows by actor made this same narrowing
 * inline; it is written once here so the reading of "no authenticated actor" cannot drift between them.
 */
final class AuthenticatedActor
{
    public static function from(ActorPort $port): ?Authenticatable
    {
        $actor = $port->actor();

        return $actor instanceof Authenticatable ? $actor : null;
    }
}
