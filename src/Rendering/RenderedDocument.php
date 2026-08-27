<?php

namespace Splicewire\Beam\Rendering;

/**
 * What a rendering hands back — a deliverable, in one of two shapes, because the endpoints being
 * collapsed onto the macro genuinely emit both and byte-parity is the point:
 *
 *  - {@see raw()}     a body string plus its content type (+ extra headers). The export endpoints'
 *                     shape: a compiled document returned as-is, `X-Cost-Usd` riding along.
 *  - {@see payload()} an already-shaped value returned to the framework untouched — a Data object, a
 *                     response envelope, an array. Lets a JSON-envelope endpoint move onto the macro
 *                     without its body being re-encoded by a second layer.
 *
 * Transport lives in the controller; this type carries no framework response object so a rendering can
 * be unit-tested without HTTP.
 */
class RenderedDocument
{
    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public bool $raw,
        public string $body,
        public ?string $contentType,
        public array $headers,
        public int $status,
        public mixed $value,
    ) {}

    /**
     * A compiled body with an explicit content type.
     *
     * @param  array<string, string>  $headers
     */
    public static function raw(string $body, string $contentType, array $headers = [], int $status = 200): self
    {
        return new self(true, $body, $contentType, $headers, $status, null);
    }

    /** An already-shaped value handed to the framework untouched. */
    public static function payload(mixed $value, int $status = 200): self
    {
        return new self(false, '', null, [], $status, $value);
    }
}
