<?php

namespace Splicewire\Beam\Localization;

use Closure;
use Illuminate\Translation\Translator;

/**
 * The application's translator in a beam host: one ladder answers every lookup (localization ticket 07, ruling 05 §2).
 *
 * The **locale chain is the outer loop** (the requested locale, its parent languages, then the fallback and its
 * parents: {@see LocaleChain}). The **realm chain is the inner loop**: the realm, then its stack from most to least
 * specific, then the base. At each locale:
 *
 *   1. each realm rung's file, `lang/<locale>/<realm>/<group>.php` (sparse; the framework's own loader reads it as
 *      the group `<realm>/<group>`, host vendor overrides included);
 *   2. in the SOURCE language only, at that same rung and just below its file, the realm's configured resource
 *      `label` (`beam.core.realm_resource_overrides`) for a derived `resources.<key>.label` key (ruling 05 §2);
 *   3. the base file, `lang/<locale>/<group>.php`.
 *
 * So a generic wording in the reader's language beats realm wording in English, and a realm's English word beats
 * a host's generic English override. No realm (a console command, a queued job with nothing scoped) means the base.
 *
 * **The realm is never stored here.** Beam keeps no ambient realm (see {@see \Splicewire\Beam\BeamManager}): the default
 * realm is read from the CURRENT request's `realm` route parameter at each lookup, and an emit point that already has
 * a realm passes it with {@see getIn()} / {@see choiceIn()}. A long-lived worker therefore never serves one request's
 * realm to the next.
 *
 * **Plurals** are selected by the rules of the locale whose line answered, normalized by {@see PluralLocale}, not by
 * the requested locale (the framework's `localeForChoice()` skips a parent language: research/00 B6). Dates and
 * numbers still format in the active locale; only selection follows the answering line.
 *
 * Bound as the application's `translator`, so `__()`, `trans_choice()`, Blade's `@lang` and validation messages all
 * take it. Binding a host's translator from a package is a host-wide effect; beam's README says so.
 */
class BeamTranslator extends Translator
{
    /** @var (Closure(): ?string)|null the default realm: the current request's, read per lookup */
    protected ?Closure $realmResolver = null;

    /** @var (Closure(string): list<string>)|null a realm's stack, most to least specific */
    protected ?Closure $stackResolver = null;

    /** @var (Closure(string, string): ?string)|null a realm's configured label for a resource key */
    protected ?Closure $realmLabelResolver = null;

    /** @var callable(list<string>): list<string>|null a host's reshaping of the locale chain ({@see determineLocalesUsing()}) */
    protected $hostLocales = null;

    /** The language the declarations and source files are written in; a realm's configured label is a line of it. */
    protected string $sourceLanguage = 'en';

    public function resolveRealmsUsing(?Closure $realm, ?Closure $stack): static
    {
        $this->realmResolver = $realm;
        $this->stackResolver = $stack;

        return $this;
    }

    public function resolveRealmLabelsUsing(?Closure $labels): static
    {
        $this->realmLabelResolver = $labels;

        return $this;
    }

    public function setSourceLanguage(string $language): static
    {
        $this->sourceLanguage = LocaleChain::canonical($language);

        return $this;
    }

    public function sourceLanguage(): string
    {
        return $this->sourceLanguage;
    }

    /**
     * Forget every group loaded for a namespace. {@see TextNamespaces} calls it when it hints a namespace, because a
     * lookup made before the hint existed has cached the namespace's groups as empty, and the framework never reloads a
     * loaded group.
     */
    public function forgetLoadedNamespace(string $namespace): void
    {
        unset($this->loaded[$namespace]);
    }

    /**
     * Text an emit point renders that did NOT come from the catalog (a declared literal, the default when the ladder
     * missed), presented for the active locale: under the pseudo-locale it is marked like a catalog line, so a
     * walkthrough sees it resolved; otherwise unchanged.
     */
    public function present(string $text, ?string $locale = null): string
    {
        return Locales::isPseudo($locale ?: $this->locale) ? PseudoLocale::transform($text) : $text;
    }

    /**
     * @param  string|array<array-key, mixed>  $line
     * @return string|array<array-key, mixed>
     */
    private static function pseudoLine(string|array $line): string|array
    {
        if (is_string($line)) {
            return PseudoLocale::transform($line);
        }
        array_walk_recursive($line, static function (&$value): void {
            $value = is_string($value) ? PseudoLocale::transform($value) : $value;
        });

        return $line;
    }

    /** The current request's realm, as a lookup with no explicit realm would read it; null when there is none. */
    public function requestRealm(): ?string
    {
        return $this->currentRealm();
    }

    /**
     * Laravel's `:name` replacement (case-aware, one pass) on a line this translator did not load: a coded error's
     * declared English when the ladder missed.
     *
     * @param  array<string, mixed>  $replace
     */
    public function replaceIn(string $line, array $replace): string
    {
        return $this->makeReplacements($line, $replace);
    }

    /**
     * @param  string  $key
     * @param  array<string, mixed>  $replace
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string|array<array-key, mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        return $this->lookup($key, $replace, $locale, $fallback, $this->currentRealm());
    }

    /**
     * {@see get()} at an explicit realm (null: the base), for an emit point that already holds one.
     *
     * @param  array<string, mixed>  $replace
     * @return string|array<array-key, mixed>
     */
    public function getIn(?string $realm, string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string|array
    {
        return $this->lookup($key, $replace, $locale, $fallback, $realm);
    }

    /**
     * @param  string  $key
     * @param  \Countable|int|float|array<array-key, mixed>  $number
     * @param  array<string, mixed>  $replace
     * @param  string|null  $locale
     * @return string
     */
    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->choiceIn($this->currentRealm(), $key, $number, $replace, $locale);
    }

    /**
     * {@see choice()} at an explicit realm. The line is selected by the rules of the locale that ANSWERED it.
     *
     * @param  \Countable|int|float|array<array-key, mixed>  $number
     * @param  array<string, mixed>  $replace
     */
    public function choiceIn(?string $realm, string $key, mixed $number, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?: $this->locale;

        // A JSON (sentence-keyed) line answers a plural exactly as it answers get(): first, for the requested locale,
        // which is then the answering locale whose rules select the form.
        $json = $this->jsonLine($key, $locale);
        $resolved = $json !== null
            ? new ResolvedLine($json, LocaleChain::canonical($locale), null, 'json')
            : $this->resolve($key, $locale, true, $realm);
        $line = $resolved?->line;

        if (! is_string($line)) {
            $line = $this->handleMissingTranslationKey($key, $replace, $locale, true);
        }

        if (is_countable($number)) {
            $number = count($number);
        }

        if (! isset($replace['count'])) {
            $replace['count'] = $number;
        }

        $selectorLocale = PluralLocale::forSelector($resolved->sourceLocale ?? $locale);

        return $this->makeReplacements($this->getSelector()->choose($line, $number, $selectorLocale), $replace);
    }

    /**
     * The ladder's answer with its provenance, or null when every rung missed. No replacement, no missing-key handling.
     */
    public function resolve(string $key, ?string $locale = null, bool $fallback = true, ?string $realm = null): ?ResolvedLine
    {
        return $this->walk($key, $locale ?: $this->locale, $fallback, $realm);
    }

    /**
     * Every rung tried for a key, in order, and which one answered (the `beam:lang:rungs` command reads it).
     *
     * @return array{rungs: list<array{locale: string, realm: ?string, origin: string, hit: bool}>, answer: ?ResolvedLine}
     */
    public function trace(string $key, ?string $locale = null, ?string $realm = null): array
    {
        $rungs = [];
        $answer = $this->walk($key, $locale ?: $this->locale, true, $realm, $rungs);

        return ['rungs' => $rungs, 'answer' => $answer];
    }

    /**
     * The locale chain of the outer loop. A host's `determineLocalesUsing()` callback still has the last word.
     *
     * @param  string|null  $locale
     * @return list<string>
     */
    protected function localeArray($locale)
    {
        $chain = LocaleChain::for($locale ?: $this->locale, $this->fallback);
        $determined = $this->hostLocales === null ? $chain : (array) call_user_func($this->hostLocales, $chain);

        return array_values(array_unique(array_filter($determined)));
    }

    /**
     * The framework's hook for a host to reshape the locale chain. Kept here as well, in a nullable property, because
     * the framework types its own copy as always set.
     *
     * @param  callable(list<string>): list<string>  $callback
     * @return void
     */
    public function determineLocalesUsing($callback)
    {
        $this->hostLocales = $callback;
        parent::determineLocalesUsing($callback);
    }

    /**
     * @param  array<string, mixed>  $replace
     * @return string|array<array-key, mixed>
     */
    protected function lookup(string $key, array $replace, ?string $locale, bool $fallback, ?string $realm): string|array
    {
        $locale = $locale ?: $this->locale;

        // A JSON (sentence-keyed) line is the framework's: it is tried first, for the requested locale, as before.
        $json = $this->jsonLine($key, $locale);
        if ($json !== null) {
            return $this->makeReplacements($json, $replace);
        }

        $resolved = $this->walk($key, $locale, $fallback, $realm);

        if ($resolved === null) {
            return $this->makeReplacements($this->handleMissingTranslationKey($key, $replace, $locale, $fallback), $replace);
        }

        if (is_array($resolved->line)) {
            $line = $resolved->line;
            array_walk_recursive($line, function (&$value) use ($replace): void {
                $value = is_string($value) ? $this->makeReplacements($value, $replace) : $value;
            });

            return $line;
        }

        return $this->makeReplacements($resolved->line, $replace);
    }

    /**
     * The first of several keys the ladder answers, keys being tried INNERMOST: at each locale (outer) and each realm
     * rung (inner), every key in order before the next rung. A column label's chain is this (ruling 01 §6): its context
     * key, then its property's title key, at each rung.
     *
     * @param  list<string>  $keys
     */
    public function resolveFirst(array $keys, ?string $locale = null, bool $fallback = true, ?string $realm = null): ?ResolvedLine
    {
        return $keys === [] ? null : $this->walk($keys, $locale ?: $this->locale, $fallback, $realm);
    }

    /**
     * The ladder. `$trace`, when given, collects every rung tried. With several keys, each rung tries them in order.
     *
     * @param  string|list<string>  $keys
     * @param  list<array{locale: string, realm: ?string, origin: string, hit: bool}>|null  $trace
     */
    protected function walk(string|array $keys, string $locale, bool $fallback, ?string $realm, ?array &$trace = null): ?ResolvedLine
    {
        // The pseudo-locale (en-XA) is English, marked and padded (localization 06): resolve the source-language line
        // through the same ladder, then transform it. Its plural rules stay the source language's.
        if (Locales::isPseudo($locale)) {
            $line = $this->walk($keys, $this->sourceLanguage, $fallback, $realm, $trace);

            return $line === null ? null : new ResolvedLine(self::pseudoLine($line->line), $line->sourceLocale, $line->realm, $line->origin);
        }

        $parsed = [];
        $groups = [];
        foreach ((array) $keys as $key) {
            [$namespace, $group, $item] = $this->parseKey($key);
            if ($item !== null) {
                $parsed[] = [$namespace, $group, $item];
            } else {
                // A key with no item names a whole group (`messages`, `ns::messages`), as it does for the framework.
                $groups[] = [$namespace, $group];
            }
        }

        $realms = $realm === null ? [] : $this->realmChain($realm);
        $locales = $fallback ? $this->localeArray($locale) : [$locale];

        if ($parsed === []) {
            return $groups === [] ? null : $this->walkGroup($groups[0][0], $groups[0][1], $locales, $realms, $trace);
        }

        foreach ($locales as $candidate) {
            $isSource = LocaleChain::canonical($candidate) === $this->sourceLanguage;

            foreach ($realms as $rung) {
                foreach ($parsed as [$namespace, $group, $item]) {
                    $line = $this->lineAt($namespace, $rung.'/'.$group, $candidate, $item);
                    $this->note($trace, $candidate, $rung, 'file', $line !== null);
                    if ($line !== null) {
                        return new ResolvedLine($line, LocaleChain::canonical($candidate), $rung, 'file');
                    }

                    if ($isSource && ($label = $this->realmLabel($group, $item, $rung)) !== null) {
                        $this->note($trace, $candidate, $rung, 'config', true);

                        return new ResolvedLine($label, LocaleChain::canonical($candidate), $rung, 'config');
                    }
                }
            }

            foreach ($parsed as [$namespace, $group, $item]) {
                $line = $this->lineAt($namespace, $group, $candidate, $item);
                $this->note($trace, $candidate, null, 'file', $line !== null);
                if ($line !== null) {
                    return new ResolvedLine($line, LocaleChain::canonical($candidate), null, 'file');
                }
            }
        }

        return null;
    }

    /**
     * A whole-group read: at the first locale in the chain where the base or any realm rung holds lines, the base
     * group with each realm rung merged over it, least specific first, so the requested realm wins key by key. Like the
     * framework, a group read never merges across locales. A realm's configured labels are single lines, not a group
     * file, so they answer `resources.<key>.label` lookups and are not merged into a whole-group read.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $realms  most to least specific
     * @param  list<array{locale: string, realm: ?string, origin: string, hit: bool}>|null  $trace
     */
    protected function walkGroup(string $namespace, string $group, array $locales, array $realms, ?array &$trace = null): ?ResolvedLine
    {
        foreach ($locales as $candidate) {
            $merged = $this->groupAt($namespace, $group, $candidate);
            $this->note($trace, $candidate, null, 'file', $merged !== []);
            $answeredBy = null;

            foreach (array_reverse($realms) as $rung) {
                $lines = $this->groupAt($namespace, $rung.'/'.$group, $candidate);
                $this->note($trace, $candidate, $rung, 'file', $lines !== []);
                if ($lines !== []) {
                    $merged = array_replace_recursive($merged, $lines);
                    $answeredBy = $rung;
                }
            }

            if ($merged !== []) {
                return new ResolvedLine($merged, LocaleChain::canonical($candidate), $answeredBy, 'file');
            }
        }

        return null;
    }

    /**
     * One locale's whole group file, as loaded (empty when absent).
     *
     * @return array<array-key, mixed>
     */
    protected function groupAt(string $namespace, string $group, string $locale): array
    {
        $this->load($namespace, $group, $locale);
        $lines = $this->loaded[$namespace][$group][$locale] ?? [];

        return is_array($lines) ? $lines : [];
    }

    /** The requested locale's JSON (sentence-keyed) line for a key, as the framework looks it up, or null. */
    protected function jsonLine(string $key, string $locale): ?string
    {
        if (Locales::isPseudo($locale)) {
            $line = $this->jsonLine($key, $this->sourceLanguage);

            return $line === null ? null : PseudoLocale::transform($line);
        }

        $this->load('*', '*', $locale);
        $line = $this->loaded['*']['*'][$locale][$key] ?? null;

        return is_string($line) ? $line : null;
    }

    /**
     * One rung's line: a string, a non-empty array, or null.
     *
     * @return string|array<array-key, mixed>|null
     */
    protected function lineAt(string $namespace, string $group, string $locale, string $item): string|array|null
    {
        $this->load($namespace, $group, $locale);
        $line = \Illuminate\Support\Arr::get($this->loaded[$namespace][$group][$locale] ?? [], $item);

        return is_string($line) || (is_array($line) && $line !== []) ? $line : null;
    }

    /**
     * The realm, then its stack from most to least specific.
     *
     * @return list<string>
     */
    protected function realmChain(string $realm): array
    {
        $stack = $this->stackResolver !== null ? (array) call_user_func($this->stackResolver, $realm) : [];

        return array_values(array_unique([$realm, ...array_reverse($stack)]));
    }

    /** A realm's configured label, for a derived `resources.<key>.label` item only (ruling 05 §2). */
    protected function realmLabel(string $group, string $item, string $realm): ?string
    {
        if ($group !== 'resources' || $this->realmLabelResolver === null
            || preg_match('/^([^.]+)\.label$/', $item, $m) !== 1) {
            return null;
        }

        // The key's identity segment writes a registry key's dots as `/` (ruling 01 §2); the registry is addressed by
        // the registry key itself, so decode it back. A registry key never contains `/` (Popcorn rejects it), so the
        // decoding is exact. The catalog key's spelling is unchanged.
        $label = call_user_func($this->realmLabelResolver, $realm, str_replace('/', '.', $m[1]));

        return is_string($label) && $label !== '' ? $label : null;
    }

    protected function currentRealm(): ?string
    {
        $realm = $this->realmResolver !== null ? call_user_func($this->realmResolver) : null;

        return is_string($realm) && $realm !== '' ? $realm : null;
    }

    /**
     * @param  list<array{locale: string, realm: ?string, origin: string, hit: bool}>|null  $trace
     */
    private function note(?array &$trace, string $locale, ?string $realm, string $origin, bool $hit): void
    {
        if ($trace !== null) {
            $trace[] = ['locale' => $locale, 'realm' => $realm, 'origin' => $origin, 'hit' => $hit];
        }
    }
}
