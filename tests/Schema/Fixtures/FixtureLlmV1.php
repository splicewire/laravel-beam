<?php

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture (LLM path) — v1 of a versioned payload: a single
 * `name` field. v2 ({@see FixtureLlmV2}) renames it to `fullName` WITHOUT a
 * #[WasNamed] hint, so no deterministic rung can infer the move — only the armed
 * LLM-try rung can. Frozen as the source version.
 */
class FixtureLlmV1 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $name,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-llm';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
