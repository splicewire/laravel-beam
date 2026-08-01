<?php

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the OLD (`test/record-versioning-expensive` v1)
 * shape that {@see FixtureExpensiveV2} supersedes with an LLM-pinned field. Frozen
 * as the source version.
 */
class FixtureExpensiveV1 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public ?string $body = null,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-expensive';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
