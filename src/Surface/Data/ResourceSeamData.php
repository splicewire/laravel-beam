<?php

namespace Splicewire\Beam\Surface\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * One **resource seam** — a single addressable operation on an API surface (`GET /api/v1/specs/{id}`),
 * as the *document* describes it.
 *
 * Deliberately stack-blind: nothing here knows about Laravel routing, Splicewire, or compliance. It is
 * the parsed shadow of one OpenAPI operation object and nothing more, so the same class carries a
 * foreign vendor's spec as faithfully as our own.
 *
 * **`security` is nullable on purpose, and null is not the empty list.** `null` means the document never
 * said — neither the operation nor the document root declared a security requirement — which is a
 * *gap*. `[]` means the document explicitly declared `security: []`, i.e. "this operation is public",
 * which is a *claim* we can agree or disagree with. Collapsing the two would turn "you didn't tell us"
 * into "you told us it's open", which is the exact conflation the corroborator exists to avoid.
 */
#[TypeScript]
class ResourceSeamData extends Data
{
    /**
     * @param  list<string>|null  $security  declared security scheme names; null ⇒ undeclared (a gap)
     * @param  array<string, string|null>  $responseShapes  status code ⇒ declared schema name
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $path,
        public string $method,
        public ?string $operationId = null,
        public ?array $security = null,
        public bool $securityOptional = false,
        public ?string $requestShape = null,
        public array $responseShapes = [],
        public array $tags = [],
    ) {}

    /** The stable identity a runtime route is matched against: `GET /api/v1/specs/{id}`. */
    public function signature(): string
    {
        return $this->method.' '.$this->path;
    }

    /** Whether the document claims this operation requires authentication of some kind. */
    public function claimsAuthentication(): bool
    {
        return $this->security !== null && $this->security !== [] && ! $this->securityOptional;
    }
}
