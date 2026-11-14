<?php

namespace Splicewire\Beam\Localization;

use Illuminate\Http\Request;
use Splicewire\Beam\Localization\Contracts\BrandLocaleSource;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;

/**
 * Where a request's language comes from (localization 06, ruling 03 §2), and where a delivery's does when there is no
 * request (§3).
 *
 * **For a request**, in order; the first supported answer wins:
 *
 *   1. an API client's `Accept-Language` (only for an API request, and only under the HTTP profile);
 *   2. the signed-in person's saved preference (the person slot; not in the reduced resolver);
 *   3. a browser's `Accept-Language` (only for a browser request, and only under the HTTP profile);
 *   4. the tenant's default (the tenant slot);
 *   5. the brand's default (the brand slot);
 *   6. the supported application default.
 *
 * **The request is classified by the credential it used**, not its route group. A bearer token makes it an API request,
 * even if a cookie is also present. A request with no session (unauthenticated or non-stateful) is an API request. A
 * request that carries a session, signed in or not (a Sanctum SPA on an `/api` route included), is a browser request.
 * The header is considered once, at 1 for an API request or at 3 for a browser one.
 *
 * **The reduced resolver** answers a request that failed before the session existed (404, 419, 401 rendered from
 * outside the request's own scope): the supported header and the defaults, never an unavailable person's preference.
 *
 * **With no request** (mail, a notification, queued work): the recipient's saved preference, the delivery context's
 * tenant default, the brand's default, the application default.
 */
final class LocaleResolver
{
    public function __construct(
        private PersonLocaleSource $person,
        private TenantLocaleSource $tenant,
        private BrandLocaleSource $brand,
    ) {}

    public function forRequest(Request $request, bool $reduced = false): LocaleResolution
    {
        $profile = (bool) config('beam.localization.http_profile', false);
        $supported = Locales::supported();
        $api = self::isApiRequest($request);
        $header = $request->headers->get('Accept-Language');

        if ($profile && $api && ($locale = LocaleNegotiator::negotiate($header, $supported)) !== null) {
            return new LocaleResolution($locale, 'api-header');
        }
        if (! $reduced && ($locale = Locales::supportedTag($this->person->locale($request))) !== null) {
            return new LocaleResolution($locale, 'person');
        }
        if ($profile && ! $api && ($locale = LocaleNegotiator::negotiate($header, $supported)) !== null) {
            return new LocaleResolution($locale, 'browser-header');
        }

        return $this->defaults($request, null);
    }

    public function forDelivery(mixed $recipient = null): LocaleResolution
    {
        if (($locale = Locales::supportedTag($this->person->locale(null, $recipient))) !== null) {
            return new LocaleResolution($locale, 'person');
        }

        return $this->defaults(null, $recipient);
    }

    /**
     * The named delivery seam (localization 36; ruling 03 §3): resolve THIS recipient's language with no request, then
     * run `$send` inside a {@see LocaleScope} in it AND in the delivery's own realm: the {@see DeliveryRecipient}'s
     * explicit realm, else the base (null). It never inherits the sender's realm (range review B12-1). `$send` receives the locale, to pass on as the Notification's or
     * Mailable's `->locale($locale)` so the framework scopes its own render the same way. One resolution per
     * recipient: a send to two people calls this twice. The sender's ambient language never takes part, and the
     * caller's locale, Number, dates and realm are restored afterwards, on a throw too.
     *
     *     $resolver->deliver(new DeliveryRecipient($user, workspace: $tenant), fn (string $locale) =>
     *         $user->notify((new SomeNotification)->locale($locale)));
     *
     * @template T
     *
     * @param  mixed  $recipient  a notifiable, or a {@see DeliveryRecipient} naming the workspace the delivery concerns
     * @param  callable(string): T  $send
     * @return T
     */
    public function deliver(mixed $recipient, callable $send): mixed
    {
        $locale = $this->forDelivery($recipient)->locale;
        $realm = $recipient instanceof DeliveryRecipient ? $recipient->realm : null;

        return LocaleScope::runIn($locale, $realm, static fn () => $send($locale));
    }

    /** API: a bearer token (cookie or not), or no session. Browser: a session, signed in or not. */
    public static function isApiRequest(Request $request): bool
    {
        if ($request->bearerToken() !== null) {
            return true;
        }

        return ! $request->hasSession();
    }

    private function defaults(?Request $request, mixed $recipient): LocaleResolution
    {
        if (($locale = Locales::supportedTag($this->tenant->locale($request, $recipient))) !== null) {
            return new LocaleResolution($locale, 'tenant');
        }
        if (($locale = Locales::supportedTag($this->brand->locale($request, $recipient))) !== null) {
            return new LocaleResolution($locale, 'brand');
        }

        return new LocaleResolution(Locales::applicationDefault(), 'default');
    }
}
