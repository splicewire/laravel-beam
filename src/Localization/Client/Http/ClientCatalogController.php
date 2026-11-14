<?php

namespace Splicewire\Beam\Localization\Client\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Localization\Client\ClientCatalogError;
use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogData;
use Splicewire\Beam\Localization\CodedAuthorizationException;
use Splicewire\Beam\Localization\CodedNotFoundHttpException;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Realm\RealmGateAbility;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * `GET beam/localization/catalog/{locale}/{realm?}`: one host's flattened, laddered client catalog (localization 16;
 * ruling 02 §4). It's the third legal Data site (`ResponseFromData`).
 *
 * - **Authorization** (B11-B1; SIGNATURES-16A): a realm's catalog is read through the realm's OWN door. It's
 *   {@see RealmGateAbility}, the one rule the realm's resources (RealmEntitlementResourceGate) and its dashboard read,
 *   asked of the user the host's middleware resolved, BEFORE the resolver, the ETag and the 304. A gated realm without
 *   the ability is a coded 403, for a guest and a wrong-realm principal alike. An ungated realm, and the realm-less base
 *   catalog, are served wherever the host's middleware admits. No ability, grant or route is added.
 * - **Middleware:** `beam.localization.client_catalog_middleware` (default `['web']`) is the host's session and tenancy
 *   initialization seam. It is not the authorization. No guard is guessed: a user another guard could authenticate is a
 *   guest here unless that middleware authenticates them.
 * - An unsupported locale or unknown realm takes the existing rejection (a coded 404). Known-403 vs unknown-404 tells
 *   realm names apart exactly as the realm's existing routes do.
 * - **Caching:** private by default. `ETag` is the content hash, and a matching `If-None-Match` answers 304, under the
 *   same authorization. `Vary: Accept-Language` comes from the opt-in HTTP profile.
 * - **Contents:** only the requested catalog, from discovered client keys. No tenant-authored or personalized value
 *   enters it.
 */
final class ClientCatalogController
{
    #[ResponseFromData(ClientCatalogData::class)]
    public function __invoke(Request $request, ClientCatalogResolver $resolver, string $locale, ?string $realm = null): JsonResponse
    {
        if (! in_array($locale, Locales::supported(), true)) {
            throw new CodedNotFoundHttpException(ClientCatalogError::LocaleUnsupported, ['locale' => $locale]);
        }
        if ($realm !== null && app(RealmRegistry::class)->tryResolve($realm) === null) {
            throw new CodedNotFoundHttpException(ClientCatalogError::RealmUnknown, ['realm' => $realm]);
        }
        if ($realm !== null) {
            $ability = RealmGateAbility::for($realm, app(RealmRegistry::class));
            if ($ability !== null && ! Gate::forUser($request->user())->allows($ability)) {
                throw new CodedAuthorizationException(ClientCatalogError::RealmForbidden, ['realm' => $realm]);
            }
        }

        $catalog = $resolver->resolve($locale, $realm);
        $response = new JsonResponse($catalog->toArray());
        $response->setEtag($catalog->hash());
        $response->setPrivate();
        $response->isNotModified($request);

        return $response;
    }
}
