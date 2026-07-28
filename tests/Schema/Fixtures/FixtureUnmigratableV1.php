<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the OLD (`test/record-versioning-unmigratable`
 * v1) shape superseded by {@see FixtureUnmigratableV2}. Frozen as the source version.
 */
class FixtureUnmigratableV1 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-unmigratable';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
