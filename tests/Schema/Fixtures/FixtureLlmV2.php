<?php

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Attributes\MigrateWith;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture (LLM path) — v2 of the versioned payload. The
 * `name` field of {@see FixtureLlmV1} is renamed to `fullName` and opts into LLM
 * migration (`#[MigrateWith('llm')]` → `x-migrate: llm`). With NO #[WasNamed] hint
 * and a `minLength: 3` constraint, the structural and declared rungs cannot satisfy
 * it (a typed-empty `''` fails the gate) — the upgrade is reachable ONLY by the
 * gated LLM-try rung, and only when armed. Its v1 is {@see FixtureLlmV1}.
 */
class FixtureLlmV2 extends Data implements SchemaIdentity
{
    public function __construct(
        #[MigrateWith(MigrateWith::LLM)]
        #[Min(3)]
        public string $fullName,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-llm';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
