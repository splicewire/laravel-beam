<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Record-versioning seam fixture — the current (`test/record-versioning-cheap` v2)
 * shape, a CHEAP structural increment over {@see FixtureCheapV1}: one added nullable
 * field (`summary`). The structural rung fills it (typed-empty), the candidate
 * validates, so a reconcile of a v1 record migrates inline and signals write-back —
 * no expensive rung, no LLM.
 */
class FixtureCheapV2 extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $summary = null,
    ) {}

    public static function schemaName(): string
    {
        return 'test/record-versioning-cheap';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
