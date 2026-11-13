<?php

namespace Splicewire\Beam\Localization;

use Carbon\Carbon;
use Illuminate\Support\Number;

/**
 * The one helper that sets AND restores locale, number formatting, dates and realm together (localization 06, ruling
 * 03 §4). Every request, job and delivery that renders text for a person runs inside one.
 *
 * Entering a scope snapshots each piece of state SEPARATELY, because they are separate: the application and translator
 * locale, `Number`'s static locale, Carbon's locale and the explicit realm. It then sets the application locale (which
 * fires `LocaleUpdated`, moving Carbon and, through beam's listener, `Number`), sets `Number` and Carbon directly as well
 * (carbon's listener exists only where its own Laravel provider is loaded), and
 * enters the realm. Leaving, in `finally` (on return or on a throw), restores the application locale first and only then
 * `Number` and Carbon to their own saved values, since restoring the application locale moves them again. A nested scope
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

        return new CapturedLocale((string) app()->getLocale(), $set, $realm);
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
