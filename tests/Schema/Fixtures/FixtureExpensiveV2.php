<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Attributes\MigrateWith;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the current (`test/record-versioning-expensive`
 * v2) shape whose new `headline` field pins an EXPENSIVE migration rung via
 * #[MigrateWith('llm')] (projected to `x-migrate`). A reconcile-on-read must NOT run
 * it: the record reads `pending` for the async drain, the ORIGINAL payload surfaced
 * untouched. The armed eager drain (LLM double-gate) targets exactly this shape. Its
 * v1 is {@see FixtureExpensiveV1}.
 */
class FixtureExpensiveV2 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public ?string $body = null,
        #[MigrateWith(MigrateWith::LLM)]
        public ?string $headline = null,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-expensive';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
