<?php

namespace Splicewire\Beam\Localization;

/**
 * The languages a host ships (`beam.localization`, ruling 03): the supported list, the pseudo-locale opt-in and the
 * application default, read from config on each call.
 */
final class Locales
{
    /** The pseudo-locale's tag: English, marked and padded (ruling 03 §2; ticket 20). */
    public const PSEUDO = 'en-XA';

    /**
     * Canonical supported tags, the pseudo-locale included only when opted in.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        $supported = array_map(
            static fn (mixed $tag): string => LocaleChain::canonical((string) $tag),
            // Null (not listed): `app.locale` alone, so an unconfigured host keeps the language it already answers in.
            (array) (config('beam.localization.supported') ?? [self::configuredLocale()]),
        );
        if ((bool) config('beam.localization.pseudo', false)) {
            $supported[] = self::PSEUDO;
        }

        return array_values(array_unique(array_filter($supported)));
    }

    /** The supported tag a candidate names exactly, or null. Comparison is on canonical tags. */
    public static function supportedTag(?string $candidate): ?string
    {
        if ($candidate === null || trim($candidate) === '') {
            return null;
        }
        $canonical = LocaleChain::canonical($candidate);

        return in_array($canonical, self::supported(), true) ? $canonical : null;
    }

    /**
     * The supported application default: `app.locale` when supported, else the configured fallback, else English. Inside
     * a scope, `app.locale` is the one from before the outermost scope ({@see LocalizationContext::base()}), since
     * `App::setLocale()` rewrites the key to the scope's locale.
     */
    public static function applicationDefault(): string
    {
        return self::supportedTag(self::configuredLocale())
            ?? self::supportedTag((string) config('beam.localization.fallback', 'en'))
            ?? LocaleChain::canonical((string) config('beam.localization.fallback', 'en'));
    }

    /** `app.locale` as the host configured it: from before the outermost scope when one is open. */
    private static function configuredLocale(): string
    {
        return app(LocalizationContext::class)->base() ?? (string) config('app.locale', 'en');
    }

    public static function isPseudo(?string $locale): bool
    {
        return $locale !== null && LocaleChain::canonical($locale) === self::PSEUDO;
    }
}
