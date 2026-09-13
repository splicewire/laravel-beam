<?php

namespace Splicewire\Beam\Tests\Fixtures\Backing;

use Splicewire\Beam\Particle\Backing\CollectionBacking;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;

/**
 * One arm of a composite, over rows held in memory — the shape a service-backed read-model takes
 * (`ReviewInbox`'s four producers are exactly this), with the domain removed.
 *
 * It reads ONE facet, `label`, so the suite can prove `$filters` reaches every arm verbatim; every
 * other key is ignored, which is what the opaque-bag contract requires of an arm.
 */
class InMemoryArm extends CollectionBacking implements ResolvesRecord
{
    /** @var list<ArmRow> */
    protected array $items;

    public function __construct(ArmRow ...$items)
    {
        $this->items = $items;
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        foreach ($this->rows($filters) as $row) {
            if ($row->id === $id) {
                return new ResolvedRecord(ArmRowData::fromArmRow($row), 'https://schemas.test/arm-row.json');
            }
        }

        return null;
    }

    protected function sortKey(): string
    {
        return 'at';
    }

    /** @return list<ArmRow> deliberately UNORDERED — ordering is {@see CollectionBacking}'s job. */
    protected function rows(array $filters): iterable
    {
        $needle = trim((string) ($filters['label'] ?? ''));

        if ($needle === '') {
            return $this->items;
        }

        return array_values(array_filter(
            $this->items,
            fn (ArmRow $row): bool => str_contains($row->label, $needle),
        ));
    }
}
