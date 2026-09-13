<?php

namespace Splicewire\Beam\Tests\Particle\Backing;

use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\CompositeBacking;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\Merge\MergeStrategyRegistry;
use Splicewire\Beam\Particle\Backing\Merge\OrderedMergeStrategy;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRow;
use Splicewire\Beam\Tests\Fixtures\Backing\DeclaringArm;
use Splicewire\Beam\Tests\Fixtures\Backing\InMemoryArm;
use Splicewire\Beam\Tests\TestCase;

/**
 * The ordered composite backing (composite-backing ticket 03) at its own seam: N arms in, one ordered
 * cursor-paged list out, one row resolved back to the arm it came from.
 *
 * The fixture is three arms with INTERLEAVED sort values, which is the only shape that can tell a real
 * k-way merge apart from "concatenate the arms and sort the page" — the latter passes a single-page
 * assertion and then loses rows the moment a cursor is involved. Expected total order, descending on
 * `at`:
 *
 *     T10 g10 | T09 a9 | T08 b8 | T07 a7 | T06 b6 | T05 a5 | T04 b4 | T03 a3 | T02 b2 | T01 a1
 *
 * so at `perPage: 3` every page but the first draws from at least two arms, `gamma` exhausts after page
 * one, `beta` after page three, and `alpha` is the only arm alive on the last page.
 */
class CompositeBackingTest extends TestCase
{
    private function composite(): CompositeBacking
    {
        return new CompositeBacking([
            'alpha' => new InMemoryArm(
                new ArmRow('a1', 'alpha', 'Alpha one', 'T01'),
                new ArmRow('a9', 'alpha', 'Alpha nine', 'T09'),
                new ArmRow('a5', 'alpha', 'Alpha five', 'T05'),
                new ArmRow('a3', 'alpha', 'Alpha three', 'T03'),
                new ArmRow('a7', 'alpha', 'Alpha seven', 'T07'),
            ),
            'beta' => new InMemoryArm(
                new ArmRow('b2', 'beta', 'Beta two', 'T02'),
                new ArmRow('b8', 'beta', 'Beta eight', 'T08'),
                new ArmRow('b4', 'beta', 'Beta four', 'T04'),
                new ArmRow('b6', 'beta', 'Beta six', 'T06'),
            ),
            'gamma' => new InMemoryArm(
                new ArmRow('g10', 'gamma', 'Gamma ten', 'T10'),
            ),
        ], 'at');
    }

    /** @return list<string> */
    private function ids(CursorPaginator $page): array
    {
        return array_map(fn (ArmRow $row): string => $row->id, $page->items());
    }

    /**
     * Every page, walked by following the composite's own cursor.
     *
     * @return list<list<string>>
     */
    private function walk(CompositeBacking $composite, array $filters, int $perPage, int $limit = 25): array
    {
        $pages = [];
        $cursor = null;

        do {
            $page = $composite->records($filters, $cursor, $perPage);
            $pages[] = $this->ids($page);
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null && count($pages) < $limit);

        return $pages;
    }

    // ── Page composition ──────────────────────────────────────────────────────────────────────────

    public function test_a_page_is_the_head_of_the_merged_stream_not_the_head_of_one_arm(): void
    {
        $this->assertSame(['g10', 'a9', 'b8'], $this->ids($this->composite()->records([], null, 3)));
    }

    public function test_one_page_wide_enough_for_everything_is_the_whole_ordered_stream(): void
    {
        $page = $this->composite()->records([], null, 25);

        $this->assertSame(
            ['g10', 'a9', 'b8', 'a7', 'b6', 'a5', 'b4', 'a3', 'b2', 'a1'],
            $this->ids($page),
        );
        $this->assertFalse($page->hasMorePages());
        $this->assertNull($page->nextCursor());
    }

    // ── Cursor round-trip ─────────────────────────────────────────────────────────────────────────

    public function test_the_cursor_resumes_every_arm_where_it_stopped_across_four_pages(): void
    {
        $pages = $this->walk($this->composite(), [], 3);

        // Four pages, each the rows AFTER the previous one — the property the scan-for-the-last-id
        // paging this replaces could not hold once an arm ran out mid-page.
        $this->assertSame([
            ['g10', 'a9', 'b8'],
            ['a7', 'b6', 'a5'],
            ['b4', 'a3', 'b2'],
            ['a1'],
        ], $pages);
    }

    public function test_paging_loses_nothing_and_repeats_nothing_at_any_page_size(): void
    {
        $whole = $this->ids($this->composite()->records([], null, 25));

        foreach ([1, 2, 3, 4, 7] as $perPage) {
            $walked = array_merge(...$this->walk($this->composite(), [], $perPage));

            $this->assertSame($whole, $walked, "perPage {$perPage} did not reproduce the ordered stream");
            $this->assertSame($walked, array_values(array_unique($walked)), "perPage {$perPage} repeated a row");
        }
    }

    public function test_an_exhausted_arm_stops_contributing_while_the_rest_keep_paging(): void
    {
        // `gamma` has one row and is spent after page one; `beta` after page three. A merge that
        // re-asked an exhausted arm for a page and mistook its empty answer for "the stream ended"
        // would stop here at one page, and one that never advanced its cursor would loop forever.
        $pages = $this->walk($this->composite(), [], 3);

        $this->assertCount(4, $pages);
        $this->assertSame(['a1'], $pages[3]);
    }

    public function test_a_cursor_the_composite_did_not_mint_is_a_first_page_rather_than_an_error(): void
    {
        $first = $this->ids($this->composite()->records([], null, 3));

        $this->assertSame($first, $this->ids($this->composite()->records([], 'not-a-cursor', 3)));
    }

    public function test_a_cursor_whose_row_has_since_been_removed_resumes_after_it_rather_than_restarting(): void
    {
        $composite = $this->composite();
        $cursor = $composite->records([], null, 3)->nextCursor()?->encode();

        $this->assertNotNull($cursor);

        // The same cursor, against a stream whose page-one rows are gone — the NORMAL case on a review
        // queue, where the rows a reviewer just saw are the rows they just approved.
        $narrowed = new CompositeBacking([
            'alpha' => new InMemoryArm(
                new ArmRow('a7', 'alpha', 'Alpha seven', 'T07'),
                new ArmRow('a5', 'alpha', 'Alpha five', 'T05'),
            ),
            'beta' => new InMemoryArm(
                new ArmRow('b6', 'beta', 'Beta six', 'T06'),
            ),
            'gamma' => new InMemoryArm,
        ], 'at');

        $this->assertSame(['a7', 'b6', 'a5'], $this->ids($narrowed->records([], $cursor, 3)));
    }

    // ── The `source` discriminator ────────────────────────────────────────────────────────────────

    public function test_the_source_filter_narrows_the_arm_set_rather_than_filtering_rows_after_the_merge(): void
    {
        $this->assertSame(['a9', 'a7', 'a5'], $this->ids($this->composite()->records(['source' => 'alpha'], null, 3)));
    }

    public function test_the_source_filter_reads_as_set_membership_like_every_other_multiselect_facet(): void
    {
        $this->assertSame(
            ['g10', 'b8', 'b6', 'b4', 'b2'],
            $this->ids($this->composite()->records(['source' => 'beta,gamma'], null, 25)),
        );
        $this->assertSame(
            ['g10', 'b8'],
            $this->ids($this->composite()->records(['source' => ['gamma', 'beta']], null, 2)),
        );
    }

    public function test_an_empty_source_value_narrows_nothing_so_the_detail_routes_blank_default_is_inert(): void
    {
        // `ParticleFrameResourceHandler::resolvedShow()` sets `source` unconditionally, to '' when the
        // caller sent none. That must not mean "no arms".
        $this->assertCount(10, $this->composite()->records(['source' => ''], null, 25)->items());
    }

    public function test_a_source_naming_no_arm_is_an_empty_page_not_the_unnarrowed_list(): void
    {
        $page = $this->composite()->records(['source' => 'delta'], null, 3);

        $this->assertSame([], $this->ids($page));
        $this->assertFalse($page->hasMorePages());
    }

    public function test_filters_are_forwarded_verbatim_to_every_arm(): void
    {
        // `label` is a facet only the ARMS understand; the composite neither interprets nor strips it.
        $this->assertSame(
            ['b8', 'b6', 'b4', 'b2'],
            $this->ids($this->composite()->records(['label' => 'Beta'], null, 25)),
        );
    }

    // ── resolve() ─────────────────────────────────────────────────────────────────────────────────

    public function test_resolve_dispatches_on_the_source_discriminator(): void
    {
        $shared = new CompositeBacking([
            'alpha' => new InMemoryArm(new ArmRow('same', 'alpha', 'From alpha', 'T02')),
            'beta' => new InMemoryArm(new ArmRow('same', 'beta', 'From beta', 'T01')),
        ], 'at');

        $this->assertSame('From beta', $shared->resolve('same', ['source' => 'beta'])->record->label);
        $this->assertSame('From alpha', $shared->resolve('same', ['source' => 'alpha'])->record->label);
    }

    public function test_resolve_without_a_discriminator_answers_from_the_first_arm_that_has_the_row(): void
    {
        $composite = $this->composite();

        $this->assertSame('Beta six', $composite->resolve('b6', [])->record->label);
        $this->assertSame('https://schemas.test/arm-row.json', $composite->resolve('b6', [])->schemaRef);
    }

    public function test_resolve_answers_null_when_the_discriminator_excludes_the_row(): void
    {
        $this->assertNull($this->composite()->resolve('b6', ['source' => 'alpha']));
        $this->assertNull($this->composite()->resolve('nothing-here', []));
    }

    // ── Capabilities ──────────────────────────────────────────────────────────────────────────────

    public function test_a_composite_streams_resolves_and_declares_but_declines_to_write(): void
    {
        $composite = $this->composite();

        $this->assertInstanceOf(StreamsRecords::class, $composite);
        $this->assertInstanceOf(ResolvesRecord::class, $composite);
        $this->assertInstanceOf(DeclaresFilterVocabulary::class, $composite);
        $this->assertNotInstanceOf(WritesRecords::class, $composite);

        // Capability is the ceiling, so an affordance opened over a composite is a REGISTRATION error.
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(BackingResolver::class)->assertAffordancesWithinCapability(
            'composite-probe',
            $composite,
            ['creatable' => true],
        );
    }

    public function test_the_vocabulary_declares_source_over_the_arm_keys_and_unions_what_the_arms_declare(): void
    {
        $composite = new CompositeBacking([
            'alpha' => new DeclaringArm(new ArmRow('a1', 'alpha', 'Alpha one', 'T01')),
            'beta' => new DeclaringArm(new ArmRow('b1', 'beta', 'Beta one', 'T02')),
        ], 'at');

        $vocabulary = $composite->filterVocabulary();

        // `label` is declared by BOTH arms and appears ONCE: a facet name is the wire key, and two arms
        // answering the same question about their own rows is one control, not two.
        $this->assertSame(['source', 'label'], $vocabulary->names());

        $source = $vocabulary->toSchema()['properties']['source']['x-filter'];

        $this->assertSame('multiselect', $source['control']);
        $this->assertSame([
            ['value' => 'alpha', 'label' => 'Alpha'],
            ['value' => 'beta', 'label' => 'Beta'],
        ], $source['options']);
    }

    public function test_a_composite_whose_arms_declare_nothing_still_declares_source(): void
    {
        // Never empty, because a declared vocabulary outranks a data-filters registration (ADR-0219) —
        // a capability that could answer with nothing would be able to blank a working panel.
        $this->assertSame(['source'], $this->composite()->filterVocabulary()->names());
    }

    // ── The merge socket ──────────────────────────────────────────────────────────────────────────

    public function test_beam_plugs_the_ordered_default_into_its_own_socket_under_a_bare_handle(): void
    {
        $registry = $this->app->make(MergeStrategyRegistry::class);

        $this->assertInstanceOf(OrderedMergeStrategy::class, $registry->resolve('ordered'));
        $this->assertSame($registry, $this->app->make(MergeStrategyRegistry::class));
        $this->assertSame(
            ['beam.particle.merge.ordered'],
            array_map(strval(...), $registry->keys()),
        );
    }

    public function test_the_merge_registry_is_described_into_the_registry_index(): void
    {
        $index = $this->app->make(RegistryIndex::class);

        $this->assertSame(
            $this->app->make(MergeStrategyRegistry::class),
            $index->ownerOf(Key::of('beam.particle.merge')),
        );
    }

    public function test_an_ordered_merge_with_no_arms_is_a_declaration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OrderedMergeStrategy)->merge([], [], null, 10, 'at');
    }
}
