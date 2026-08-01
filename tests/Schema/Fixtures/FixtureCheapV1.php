<?php

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the OLD (`test/record-versioning-cheap` v1)
 * shape a stored record was written under. Frozen into the throwaway registry as
 * the source version; {@see FixtureCheapV2} supersedes it with a cheap structural
 * increment. A throwaway `$id`-versioned record type the port tests drive directly.
 */
class FixtureCheapV1 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public ?string $body = null,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-cheap';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
