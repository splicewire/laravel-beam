<?php

namespace Splicewire\Beam\Tests\Particle;

use Rushing\DataFilters\Keywords;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Tests\TestCase;

/**
 * The vocabulary a streams-only backing declares (composite-backing ticket 02) projects to the SAME
 * `x-filter` / `x-sort` dialect `rushing/laravel-data-filters` emits off a `#[Filterable]` Data class —
 * the FilterPanel is one client over one schema shape, and a second dialect would mean a second panel.
 *
 * The shapes asserted here are the ones `@schemastud/facets` `types.ts` names (`FilterDescriptor`,
 * `SortDescriptor`), keyed under data-filters' own `Keywords` constants rather than literals so a
 * keyword rename lands here and not in a blank panel.
 */
class FilterVocabularyTest extends TestCase
{
    public function test_a_set_facet_projects_to_a_multiselect_over_an_options_source(): void
    {
        $property = DeclaredFacet::set('source', options: 'review_sources')->toProperty();

        $this->assertSame([
            'operator' => 'set',
            'name' => 'source',
            'control' => 'multiselect',
            'optionsRef' => 'review_sources',
            'valueKey' => 'value',
            'labelKey' => 'label',
            'searchable' => true,
        ], $property[Keywords::Filter]);
        $this->assertArrayNotHasKey(Keywords::Sort, $property);
    }

    public function test_a_set_facet_may_inline_a_finite_domain_instead_of_referencing_one(): void
    {
        $property = DeclaredFacet::set('status', inline: [
            ['value' => 'open', 'label' => 'Open'],
            ['value' => 'closed', 'label' => 'Closed'],
        ])->toProperty();

        $this->assertSame([
            ['value' => 'open', 'label' => 'Open'],
            ['value' => 'closed', 'label' => 'Closed'],
        ], $property[Keywords::Filter]['options']);
        $this->assertArrayNotHasKey('optionsRef', $property[Keywords::Filter]);
    }

    public function test_a_search_facet_is_the_panels_leading_search_input(): void
    {
        $property = DeclaredFacet::search('keywords')->toProperty();

        $this->assertSame(
            ['operator' => 'search', 'name' => 'keywords', 'control' => 'search'],
            $property[Keywords::Filter],
        );
    }

    public function test_a_sortable_facet_carries_both_keywords_and_a_sort_only_field_carries_one(): void
    {
        $both = DeclaredFacet::exact('label')->sortable()->toProperty();
        $sortOnly = DeclaredFacet::sort('createdAt')->toProperty();

        $this->assertSame('exact', $both[Keywords::Filter]['operator']);
        $this->assertSame(['name' => 'label'], $both[Keywords::Sort]);

        $this->assertArrayNotHasKey(Keywords::Filter, $sortOnly);
        $this->assertSame(['name' => 'createdAt'], $sortOnly[Keywords::Sort]);
    }

    public function test_the_schema_is_an_object_keyed_by_facet_name(): void
    {
        $schema = FilterVocabulary::of(
            DeclaredFacet::set('source', options: 'review_sources'),
            DeclaredFacet::search('keywords'),
        )->toSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['source', 'keywords'], array_keys($schema['properties']));
        $this->assertSame('source', $schema['properties']['source'][Keywords::Filter]['name']);
    }

    /**
     * `(object) []` and never `[]` — the wire contract's `properties` is an object (`FilterSchema` in
     * `@schemastud/facets`), and an empty PHP array would encode as a JSON ARRAY. The same trap
     * `ResourceFiltersController::declaredEmptyVocabulary()` documents, guarded at the source.
     */
    public function test_an_empty_vocabulary_still_encodes_properties_as_an_object(): void
    {
        $vocabulary = FilterVocabulary::of();

        $this->assertTrue($vocabulary->isEmpty());
        $this->assertSame('{"type":"object","properties":{}}', json_encode($vocabulary->toSchema()));
    }

    public function test_it_knows_which_option_sources_it_references(): void
    {
        $vocabulary = FilterVocabulary::of(
            DeclaredFacet::set('source', options: 'review_sources'),
            DeclaredFacet::set('parentId', options: 'review_parents'),
            DeclaredFacet::search('keywords'),
        );

        $this->assertSame(['source', 'parentId', 'keywords'], $vocabulary->names());
        $this->assertSame(['review_sources', 'review_parents'], $vocabulary->optionsRefs());
        $this->assertTrue($vocabulary->references('review_parents'));
        $this->assertFalse($vocabulary->references('silos'));
    }

    public function test_two_facets_may_not_share_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[source]');

        FilterVocabulary::of(DeclaredFacet::set('source'), DeclaredFacet::search('source'));
    }
}
