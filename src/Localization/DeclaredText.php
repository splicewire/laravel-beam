<?php

namespace Splicewire\Beam\Localization;

/**
 * Declared text, resolved where it is emitted (localization ruling 01; tickets 09 and 10). One lookup for every derived
 * key: build the key from the declaring class's owner and the kind's production, walk the ladder for the request's
 * locale and realm, and fall back to the declared literal when every rung misses. Never translates at registration;
 * never asks the translator for a literal.
 *
 * A class whose owner's namespace is refused (ruling 01 §3) has no derived key: its literal renders as declared.
 */
final class DeclaredText
{
    public function __construct(private TextNamespaces $namespaces) {}

    /**
     * The derived key for a class-owned declaration, or null when the owner is refused.
     *
     * @param  'data'|'enums'  $kind
     * @param  string  $item  the production after the identity, e.g. `title`, `properties.path.title`, `cases.Tsx.label`
     */
    public function key(string $kind, string $class, string $item): ?string
    {
        $prefix = $this->namespaces->ownerOfClass($class)->keyPrefix();

        return $prefix === null ? null : $prefix.$kind.'.'.str_replace('\\', '/', ltrim($class, '\\')).'.'.$item;
    }

    /**
     * The ladder's answer for a derived key, or `$default` when it misses (or the key is null).
     */
    public function resolve(?string $key, ?string $default, ?string $realm = null): ?string
    {
        $translator = app('translator');

        if ($key === null || ! $translator instanceof BeamTranslator) {
            return $default;
        }

        $line = $translator->resolve($key, null, true, $realm ?? $translator->requestRealm())?->line;

        // A ladder miss renders the declared literal, presented for the active locale (marked under the pseudo-locale).
        return is_string($line) ? $line : ($default === null ? null : $translator->present($default));
    }

    /**
     * The first of several derived keys the ladder answers, keys tried innermost at each rung (ruling 01 §6), or
     * `$default` when every rung misses. Null keys (a refused owner) are dropped.
     *
     * @param  list<?string>  $keys
     */
    public function resolveFirst(array $keys, ?string $default, ?string $realm = null): ?string
    {
        $translator = app('translator');
        $keys = array_values(array_filter($keys, static fn (?string $k): bool => $k !== null));

        if ($keys === [] || ! $translator instanceof BeamTranslator) {
            return $default;
        }

        $line = $translator->resolveFirst($keys, null, true, $realm ?? $translator->requestRealm())?->line;

        // A ladder miss renders the declared literal, presented for the active locale (marked under the pseudo-locale).
        return is_string($line) ? $line : ($default === null ? null : $translator->present($default));
    }

    /**
     * {@see key()} then {@see resolve()}.
     *
     * @param  'data'|'enums'  $kind
     */
    public function for(string $kind, string $class, string $item, ?string $default): ?string
    {
        return $this->resolve($this->key($kind, $class, $item), $default);
    }
}
