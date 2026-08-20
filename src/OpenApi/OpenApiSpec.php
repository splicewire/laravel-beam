<?php

namespace Splicewire\Beam\OpenApi;

/**
 * One resolved spec response: the bytes, the format they are in, and when the underlying artifact last
 * changed (0 when the source has no meaningful timestamp — an in-memory or remote source).
 *
 * Deliberately NOT a path: an {@see OpenApiSpecSource} that resolves a pre-generated variant, or one that
 * assembles bytes in memory, has no single file to hand back. The controller only ever needs bytes plus
 * enough to set headers.
 */
class OpenApiSpec
{
    public function __construct(
        public string $body,
        public SpecFormat $format,
        public int $lastModified = 0,
    ) {}
}
