<?php

namespace Splicewire\Beam\Localization\Client\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The localization bootstrap a host shares with its pages (localization 16; ruling 02 §4): the catalog's address, and
 * the synchronous initial catalog for SSR and first paint.
 *
 * - On an Inertia host, beam-ux shares `address` as an ordinary prop and `initial` as a once-prop keyed by host,
 *   locale, realm and hash.
 * - `initial` is null when there is no valid discovered catalog. The client then uses its compiled English defaults,
 *   which never count as coverage.
 */
#[TypeScript]
final class ClientLocalizationData extends Data
{
    public function __construct(
        public ClientCatalogAddressData $address,
        public ?ClientCatalogData $initial,
    ) {}
}
