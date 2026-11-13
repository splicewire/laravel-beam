<?php

namespace Splicewire\Beam\Localization;

use LogicException;

/**
 * A namespaced key with no line at any rung (localization 12). Thrown while unit tests run (or when
 * `beam.localization.throw_on_missing_key` is true), so a mistyped `__('beam::…')` fails its test instead of shipping as
 * a raw key. Developer text: never translated.
 */
final class MissingTranslationKey extends LogicException
{
    public static function for(string $key, string $locale): self
    {
        return new self(sprintf(
            'The translation key [%s] has no line for [%s] or any language it falls back to. Add it to the namespace\'s '
            .'lang/en file, or fix the key. Trace it with: php artisan beam:lang:rungs %s --locale=%s',
            $key, $locale, escapeshellarg($key), $locale,
        ));
    }

    /**
     * The keys the gate guards (coord-lead ruling (a) on 12): namespaced (`ns::group.item`) in a namespace a FAMILY
     * package owns. An unnamespaced host key, a sentence key and a third-party vendor's namespace never are, so a host's
     * own suite never goes red on a key it does not own.
     */
    public static function guards(string $key, TextNamespaces $namespaces): bool
    {
        if (preg_match('/^([a-z0-9][a-z0-9_.-]*)::[A-Za-z0-9_-]+\..+$/', $key, $m) !== 1) {
            return false;
        }

        return $namespaces->familyOwnersOf($m[1]) !== [];
    }
}
