<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the current (`test/record-versioning-unmigratable`
 * v2) shape that NO cheap rung can satisfy: a NON-nullable `slug` with a
 * `minLength: 1` constraint (`#[Min(1)]`), no default and no declared rename. The
 * structural rung can only fill a typed-empty `''`, which fails the constraint, so
 * the acceptance gate rejects every rung and the ladder quarantines (its null floor).
 * The reconcile therefore marks the record `failed`, preserving the ORIGINAL payload.
 */
class FixtureUnmigratableV2 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        #[Min(1)]
        public string $slug = '',
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-unmigratable';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
