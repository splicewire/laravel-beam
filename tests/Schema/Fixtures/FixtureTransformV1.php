<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the OLD (`test/record-versioning-transform` v1)
 * shape a stored record is written under. Superseded by {@see FixtureTransformV2},
 * whose new field pins an EXPENSIVE custom-transform rung: the record reads
 * `pending` on the read path and is completed by the eager drain. Frozen as source.
 */
class FixtureTransformV1 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public ?string $body = null,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-transform';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
