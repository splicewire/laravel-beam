<?php

namespace Splicewire\Beam\Localization;

use Carbon\Carbon;
use Illuminate\Support\Number;

/**
 * The one helper that sets AND restores locale, number formatting, dates and realm together (localization 06, ruling
 * 03 §4). Every request, job and delivery that renders text for a person runs inside one.
 *
 * Entering a scope snapshots each piece of state SEPARATELY, because they are separate: the application locale, the
 * translator's own locale (which a host or Octane can set apart from the application's; REVIEW-B6 B2), `Number`'s static
 * locale, Carbon's locale and the explicit realm. It then sets the application locale (which
 * fires `LocaleUpdated`, moving Carbon and, through beam's listener, `Number`), sets `Number` and Carbon directly as well
 * (carbon's listener exists only where its own Laravel provider is loaded), and
 * enters the realm. Leaving, in `finally` (on return or on a throw), restores the application locale first and only then
 * the translator, `Number` and Carbon to their own saved values, since restoring the application locale moves them
 * again. A nested scope
 * therefore restores its caller's state, not the application default.
 *
 * Currency and timezone are never touched: currency is the host's own policy, and no person or tenant carries a
 * timezone. A process-global formatter is not safe for two OVERLAPPING scopes; scopes here are sequential (a request,
 * then the next; a job, then the next).
 */
final class LocaleScope
{
    /**
     * Run `$callback` in `$locale`, keeping whatever realm is current.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(string $locale, callable $callback): mixed
    {
        return self::enter($locale, false, null, $callback);
    }

    /**
     * Run `$callback` in `$locale` and `$realm` (null: the base rung).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runIn(string $locale, ?string $realm, callable $callback): mixed
    {
        return self::enter($locale, true, $realm, $callback);
    }

    /**
     * The current locale and realm, to re-enter later: output rendered after its scope ended (a streamed body, a
     * terminable callback, a deferred job) enters this at its emit point instead of reading restored ambient state.
     */
    public static function capture(): CapturedLocale
    {
        [$set, $realm] = self::context()->realm();

        // REVIEW-B6 B1: freeze the EFFECTIVE realm. With no entered realm, the translator reads the current request's route,
        // which a later emit would see changed (a tenant request's output rendered in the next request's operator realm),
        // so the route-derived realm, a null base included, is captured as an entered one.
        if (! $set) {
            $translator = app('translator');
            $realm = $translator instanceof BeamTranslator ? $translator->requestRealm() : null;
        }

        return new CapturedLocale((string) app()->getLocale(), true, $realm);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function enter(string $locale, bool $setRealm, ?string $realm, callable $callback): mixed
    {
        $app = app();
        $context = self::context();

        $previousLocale = (string) $app->getLocale();
        // REVIEW-B6 B2: the translator's locale is its own state (Octane's FlushLocaleState, a caller's setLocale on the
        // translator alone): restoring the application locale would overwrite it, so it is saved and restored separately.
        $previousTranslator = (string) $app->make('translator')->getLocale();
        $previousNumber = (string) Number::defaultLocale();
        $previousCarbon = Carbon::getLocale();
        $previousRealm = $context->realm();

        $context->opened($previousLocale);
        try {
            $app->setLocale($locale);
            Number::useLocale($locale);
            // Carbon follows LocaleUpdated only where carbon's own Laravel provider is loaded; set it either way.
            Carbon::setLocale($locale);
            if ($setRealm) {
                $context->enter($realm);
            }

            return $callback();
        } finally {
            $app->setLocale($previousLocale);
            $app->make('translator')->setLocale($previousTranslator);
            Number::useLocale($previousNumber);
            if (Carbon::getLocale() !== $previousCarbon) {
                Carbon::setLocale($previousCarbon);
            }
            $context->restore($previousRealm);
            $context->closed();
        }
    }

    private static function context(): LocalizationContext
    {
        return app(LocalizationContext::class);
    }
}
