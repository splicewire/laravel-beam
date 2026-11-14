<?php

namespace Splicewire\Beam\Localization\Client\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Localization\Client\ClientCatalogError;
use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogData;
use Splicewire\Beam\Localization\CodedNotFoundHttpException;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * `GET beam/localization/catalog/{locale}/{realm?}`: one host's flattened, laddered client catalog (localization 16;
 * ruling 02 §4). It's the third legal Data site (`ResponseFromData`).
 *
 * - **Authorization:** it runs under the host's configured middleware (`beam.localization.client_catalog_middleware`),
 *   so no new permission surface. An unsupported locale or unknown realm takes the existing rejection (a coded 404).
 *   No realm is enumerated.
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

        $catalog = $resolver->resolve($locale, $realm);
        $response = new JsonResponse($catalog->toArray());
        $response->setEtag($catalog->hash());
        $response->setPrivate();
        $response->isNotModified($request);

        return $response;
    }
}
