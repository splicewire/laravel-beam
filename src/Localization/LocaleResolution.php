<?php

namespace Splicewire\Beam\Localization;

/**
 * A resolved locale and the slot that supplied it: `api-header`, `person`, `browser-header`, `tenant`, `brand` or
 * `default` (ruling 03 §2). `personal()` says whether the language depends on who is asking, which the HTTP profile
 * uses to keep such a response out of shared caches.
 */
final readonly class LocaleResolution
{
    public function __construct(
        public string $locale,
        public string $source,
    ) {}

    public function personal(): bool
    {
        return in_array($this->source, ['person', 'tenant'], true);
    }
}
