<?php

namespace Splicewire\Beam\Tests\Fixtures\Backing;

use Spatie\LaravelData\Data;

/** The read projection of an {@see ArmRow} — the composite fixture resource's `data:` class. */
class ArmRowData extends Data
{
    public function __construct(
        public string $id,
        public string $source,
        public string $label,
        public ?string $at = null,
    ) {}

    public static function fromArmRow(ArmRow $row): self
    {
        return new self($row->id, $row->source, $row->label, $row->at);
    }
}
