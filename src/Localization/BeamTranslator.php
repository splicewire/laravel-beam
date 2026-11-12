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
        $resolved = $this->resolve($key, $locale ?: $this->locale, true, $realm);
        $line = $resolved?->line;

        if (! is_string($line)) {
            $line = $this->handleMissingTranslationKey($key, $replace, $locale ?: $this->locale, true);
        }

        if (is_countable($number)) {
            $number = count($number);
        }

        if (! isset($replace['count'])) {
            $replace['count'] = $number;
        }

        $selectorLocale = PluralLocale::forSelector($resolved->sourceLocale ?? ($locale ?: $this->locale));

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
        $this->load('*', '*', $locale);
        $json = $this->loaded['*']['*'][$locale][$key] ?? null;
        if (is_string($json)) {
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
     * The ladder. `$trace`, when given, collects every rung tried.
     *
     * @param  list<array{locale: string, realm: ?string, origin: string, hit: bool}>|null  $trace
     */
    protected function walk(string $key, string $locale, bool $fallback, ?string $realm, ?array &$trace = null): ?ResolvedLine
    {
        [$namespace, $group, $item] = $this->parseKey($key);
        if ($item === null) {
            return null;
        }

        $realms = $realm === null ? [] : $this->realmChain($realm);
        $locales = $fallback ? $this->localeArray($locale) : [$locale];

        foreach ($locales as $candidate) {
            $isSource = LocaleChain::canonical($candidate) === $this->sourceLanguage;

            foreach ($realms as $rung) {
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

            $line = $this->lineAt($namespace, $group, $candidate, $item);
            $this->note($trace, $candidate, null, 'file', $line !== null);
            if ($line !== null) {
                return new ResolvedLine($line, LocaleChain::canonical($candidate), null, 'file');
            }
        }

        return null;
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

        $label = call_user_func($this->realmLabelResolver, $realm, $m[1]);

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
