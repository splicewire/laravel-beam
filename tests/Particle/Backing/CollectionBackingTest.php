<?php

namespace Splicewire\Beam\Tests\Particle\Backing;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRow;
use Splicewire\Beam\Tests\Fixtures\Backing\InMemoryArm;
use Splicewire\Beam\Tests\TestCase;

class CollectionBackingTest extends TestCase
{
    /** @return list<string> */
    private function ids(CursorPaginator $page): array
    {
        return array_map(fn (ArmRow $row): string => $row->id, $page->items());
    }

    public function test_ties_have_the_same_order_regardless_of_producer_order_or_page_size(): void
    {
        foreach ([['a', 'c', 'b'], ['b', 'a', 'c']] as $input) {
            $backing = new InMemoryArm(...array_map(
                fn (string $id): ArmRow => new ArmRow($id, 'alpha', $id, 'T01'),
                $input,
            ));

            foreach ([1, 2, 3, 5] as $perPage) {
                $seen = [];
                $cursor = null;
                for ($requests = 0; $requests < 5; $requests++) {
                    $page = $backing->records([], $cursor, $perPage);
                    $seen = array_merge($seen, $this->ids($page));
                    $cursor = $page->nextCursor()?->encode();
                    if ($cursor === null) {
                        break;
                    }
                }

                $this->assertNull($cursor, 'Traversal must terminate.');
                $this->assertSame(['c', 'b', 'a'], $seen);
            }
        }
    }

    public function test_removing_the_emitted_cursor_row_keeps_every_unseen_tied_row(): void
    {
        $rows = array_map(
            fn (string $id): ArmRow => new ArmRow($id, 'alpha', $id, 'T01'),
            ['a', 'b', 'c'],
        );
        $first = (new InMemoryArm(...$rows))->records([], null, 1);
        $cursor = $first->nextCursor()?->encode();
        $this->assertNotNull($cursor);
        $seenId = $first->items()[0]->id;
        $remaining = array_values(array_filter($rows, fn (ArmRow $row): bool => $row->id !== $seenId));

        $next = (new InMemoryArm(...$remaining))->records([], $cursor, 10);

        $expected = array_column($remaining, 'id');
        rsort($expected);
        $this->assertSame($expected, $this->ids($next));
        $this->assertNull($next->nextCursor());
    }

    public function test_missing_sort_values_are_last_and_page_to_a_terminal_result(): void
    {
        $backing = new InMemoryArm(
            new ArmRow('a', 'alpha', 'A'),
            new ArmRow('new', 'alpha', 'New', 'T02'),
            new ArmRow('b', 'alpha', 'B'),
            new ArmRow('old', 'alpha', 'Old', 'T01'),
        );

        $first = $backing->records([], null, 2);
        $this->assertSame(['new', 'old'], $this->ids($first));
        $cursor = $first->nextCursor()?->encode();
        $this->assertNotNull($cursor);
        $last = $backing->records([], $cursor, 2);
        $this->assertSame(['b', 'a'], $this->ids($last));
        $this->assertNull($last->nextCursor());
        $this->assertSame([], $this->ids((new InMemoryArm)->records([], null, 2)));
    }

    public function test_a_deleted_null_sort_cursor_skips_newer_rows_and_keeps_lower_tied_ids(): void
    {
        $first = (new InMemoryArm(
            new ArmRow('b', 'alpha', 'B'),
            new ArmRow('a', 'alpha', 'A'),
        ))->records([], null, 1);
        $cursor = $first->nextCursor()?->encode();
        $this->assertNotNull($cursor);

        $next = (new InMemoryArm(
            new ArmRow('new', 'alpha', 'New', 'T02'),
            new ArmRow('a', 'alpha', 'A'),
        ))->records([], $cursor, 1);

        $this->assertSame(['a'], $this->ids($next));
        $this->assertNull($next->nextCursor());
    }

    public function test_distinct_numeric_looking_identities_remain_ordered_after_cursor_deletion(): void
    {
        $rows = [new ArmRow('01', 'alpha', 'Leading zero', 'T01'), new ArmRow('1', 'alpha', 'One', 'T01')];
        $first = (new InMemoryArm(...$rows))->records([], null, 1);
        $cursor = $first->nextCursor()?->encode();
        $this->assertNotNull($cursor);
        $seenId = $first->items()[0]->id;
        $remaining = array_values(array_filter($rows, fn (ArmRow $row): bool => $row->id !== $seenId));

        $next = (new InMemoryArm(...$remaining))->records([], $cursor, 1);

        $this->assertSame(array_column($remaining, 'id'), $this->ids($next));
        $this->assertSame(['1'], $this->ids($first));
        $this->assertNull($next->nextCursor());
    }
}
