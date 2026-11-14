<?php

namespace Splicewire\Beam\Localization\Client\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Localization\Client\Jcs;
use Splicewire\Beam\Localization\Client\JsonObject;

/**
 * The flattened, laddered client catalog for one host, locale and realm (localization 16; ruling 02 §4). It maps 1:1
 * onto schemastud's `ClientCatalog` (messages/src/index.ts). Its identity is {@see hash()}: SHA-256 over RFC 8785 JCS of
 * exactly these members, with no timestamp, URL or self field.
 */
#[TypeScript]
final class ClientCatalogData extends Data
{
    /**
     * @param  array<string, ClientMessageLineData>  $messages
     */
    public function __construct(
        #[LiteralTypeScriptType('1')]
        public int $version,
        public string $locale,
        public ?string $realm,
        #[LiteralTypeScriptType("'ltr' | 'rtl'")]
        public string $direction,
        #[LiteralTypeScriptType('Record<string, ClientMessageLineData>')]
        public array $messages,
    ) {}

    /**
     * The wire shape keeps `messages` a map even when empty (B11-B7): `{}`, never `[]`. This is the one serialization
     * boundary that toArray(), jsonSerialize() and a parent Data's nested transform (the bootstrap's `initial`) share.
     *
     * @return array<string, mixed>
     */
    public function transform(
        null|\Spatie\LaravelData\Support\Transformation\TransformationContextFactory|\Spatie\LaravelData\Support\Transformation\TransformationContext $transformationContext = null,
    ): array {
        $transformed = parent::transform($transformationContext);
        if (array_key_exists('messages', $transformed) && $transformed['messages'] === []) {
            $transformed['messages'] = new \stdClass;
        }

        return $transformed;
    }

    /** The catalog's content hash: sha256(JCS(this)) in hex. `messages` is always an object, even when empty. */
    public function hash(): string
    {
        return Jcs::hash(new JsonObject([
            'version' => $this->version,
            'locale' => $this->locale,
            'realm' => $this->realm,
            'direction' => $this->direction,
            'messages' => new JsonObject(array_map(
                static fn (ClientMessageLineData $line): JsonObject => new JsonObject(['text' => $line->text, 'sourceLocale' => $line->sourceLocale]),
                $this->messages,
            )),
        ]));
    }
}
