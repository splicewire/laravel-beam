<?php

namespace Splicewire\Beam\Routing;

/**
 * Maps a PHP Data-class FQCN to the TypeScript type reference the client SDK uses
 * (client-sdk-codegen ticket 03).
 *
 * Through surgeon-audit-viability ticket 33, spatie's `typescript:transform` emitted every
 * `#[TypeScript]` Data class REMAPPED onto an ambient `App.Data.*` FE tree, and this resolver
 * mirrored that projection. **Ticket 34 dropped the remap** — every type now emits at its real
 * native PHP namespace (dots for backslashes), unconditionally, for app-owned and package-owned
 * types alike. That's collision-proof by construction (PHP FQNs are globally unique — two classes
 * can never share one), which is why the projection/package-segment machinery this resolver used
 * to duplicate is gone: there's nothing left to project.
 *
 * `->returns(SomeClass::class)` silently degrades to the bare unqualified string when the calling
 * file has no matching `use` import — PHP's `::class` resolves this way without erroring, unlike a
 * real instantiation. Left unguarded, that garbage string used to fall through to a plausible-but-
 * wrong guess here, hiding a route-file import bug behind a confusing later TypeScript compile
 * error instead of failing at the source. Guard against it explicitly.
 */
class ClientTypeName
{
    /** e.g. `Splicewire\Tower\Data\RegistryCatalogData` → `Splicewire.Tower.Data.RegistryCatalogData`. */
    public static function for(string $class): string
    {
        if (! class_exists($class)) {
            throw new \InvalidArgumentException(
                "ClientTypeName::for('{$class}'): no such class — likely a `->returns()`/`->streams()` ".
                'call on a class with no matching `use` import in the route file (PHP silently '.
                'resolves `Foo::class` to the bare string "Foo" when unimported, instead of erroring).'
            );
        }

        return str_replace('\\', '.', ltrim($class, '\\'));
    }
}
