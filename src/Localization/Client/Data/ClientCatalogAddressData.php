<?php

namespace Splicewire\Beam\Localization\Client\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a client fetches one catalog, and its content hash (localization 16; ruling 02 §4). The client treats the hash
 * as opaque identity: it does not canonicalize or re-verify it.
 */
#[TypeScript]
final class ClientCatalogAddressData extends Data
{
    public function __construct(
        public string $url,
        public string $hash,
        #[LiteralTypeScriptType('1')]
        public int $version,
        public string $locale,
        public ?string $realm,
    ) {}
}
