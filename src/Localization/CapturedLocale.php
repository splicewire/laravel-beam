<?php

namespace Splicewire\Beam\Localization;

/**
 * A locale and realm captured inside a scope, re-entered at a later emit point (ruling 03 §4: deferred output renders in
 * its own context, never in whatever the ambient state has been restored to by then).
 */
final readonly class CapturedLocale
{
    public function __construct(
        public string $locale,
        public bool $realmSet,
        public ?string $realm,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function enter(callable $callback): mixed
    {
        return $this->realmSet
            ? LocaleScope::runIn($this->locale, $this->realm, $callback)
            : LocaleScope::run($this->locale, $callback);
    }
}
