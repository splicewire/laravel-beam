<?php

namespace Splicewire\Beam\Localization\Catalog;

/**
 * One key a host can emit (localization 12), with the English it renders when no line exists.
 *
 * `source` is `derived` (a declaration's key, ruling 01) or `explicit` (a line in a `lang/en` file). `derivedEnglish`
 * is true when the English is itself a derivation (a headlined key, an inflected singular, a class short name), which
 * is a default only and what `localization.english-derivation` reports for framed resources.
 */
final readonly class CatalogEntry
{
    public function __construct(
        public string $key,
        public string $english,
        public string $source,
        public string $origin,
        public bool $derivedEnglish = false,
    ) {}

    /** The namespace (`beam`), or null for a host key. */
    public function namespace(): ?string
    {
        $position = strpos($this->key, '::');

        return $position === false ? null : substr($this->key, 0, $position);
    }

    /** The group after the namespace (`resources`). */
    public function group(): string
    {
        $rest = str_contains($this->key, '::') ? substr($this->key, strpos($this->key, '::') + 2) : $this->key;

        return strtok($rest, '.') ?: $rest;
    }

    /** @return array{key: string, english: string, source: string, origin: string, derivedEnglish: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'english' => $this->english, 'source' => $this->source, 'origin' => $this->origin, 'derivedEnglish' => $this->derivedEnglish];
    }
}
