<?php

namespace Splicewire\Beam\Localization\Contracts;

use Illuminate\Http\Request;

/**
 * One of the resolver's three adapter slots that beam does not own (ruling 03): the person's saved preference
 * (beam-accounts, ticket 36), the tenant's default (beam-tenancy, ticket 36) and the brand's default (ticket 11). Each
 * answers a locale tag or null; the resolver accepts it only when it is supported. The null adapter answers null.
 *
 * For a request, `$request` is the current request and `$recipient` is null. For delivery with no request, `$request`
 * is null and `$recipient` is the notifiable (or null).
 */
interface LocaleSource
{
    public function locale(?Request $request, mixed $recipient = null): ?string;
}
