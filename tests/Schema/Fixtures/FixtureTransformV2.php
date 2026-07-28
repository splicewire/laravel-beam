<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Attributes\MigrateWith;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the current (`test/record-versioning-transform`
 * v2) shape whose new non-nullable `slug` (a `minLength: 1` constraint, no default)
 * NO cheap rung can satisfy: the structural rung can only fill a typed-empty `''`,
 * which fails the gate. The field pins a CUSTOM transform via
 * #[MigrateWith(FixtureSlugTransform::class)] (projected to `x-migrate`), so:
 *
 *  - on READ the record is `pending` (the read ladder has no transform registry, so
 *    the custom rung abstains and the cheap ladder quarantines → deferred, not failed);
 *  - the EAGER drain runs the full ladder WITH the registry, the transform fires, the
 *    candidate conforms, and the record advances to `current`.
 *
 * Its v1 is {@see FixtureTransformV1}; the transform is {@see FixtureSlugTransform}.
 */
class FixtureTransformV2 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public ?string $body = null,
        #[Min(1)]
        #[MigrateWith(FixtureSlugTransform::class)]
        public string $slug = '',
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-transform';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
