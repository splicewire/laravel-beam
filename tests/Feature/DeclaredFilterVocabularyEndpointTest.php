<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Keywords;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\DeclaredResourceQuery;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The filter sub-surface's THIRD answer (composite-backing ticket 02): a streams-only backing that
 * declares its vocabulary is served from that declaration, beside the two answers the endpoint already
 * gave — the data-filters schema for a registered filter resource, and the authorized empty vocabulary
 * for a declaration that opted out.
 *
 * Both branches the ticket names are asserted here against the BOOTED registry singletons and a real
 * `Particle::filters()` mount, because the controller's branch order is the whole decision: a
 * declaration outranks a data-filters registration under the same key (beam ADR-0219), and that is
 * only observable at the endpoint.
 */
class DeclaredFilterVocabularyEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(ParticleResourceRegistry::class);

        $registry->register(new ParticleResource(
            key: 'feed',
            backing: DeclaringFeedBacking::class,
            data: WidgetGateData::class,
            readOnly: true,
            showable: false,
        ));
        $registry->register(new ParticleResource(
            key: 'ticker',
            backing: SilentFeedBacking::class,
            data: WidgetGateData::class,
            readOnly: true,
            showable: false,
        ));

        Particle::filters('feed', at: 'feed');
        Particle::filters('ticker', at: 'ticker');
    }

    public function test_a_declaring_backing_is_served_its_declared_vocabulary(): void
    {
        $properties = $this->getJson('feed/filters/schema')
            ->assertOk()
            ->json('data.properties');

        $this->assertSame(['source', 'parentId', 'keywords'], array_keys($properties));
        $this->assertSame('multiselect', $properties['source'][Keywords::Filter]['control']);
        $this->assertSame('feed_sources', $properties['source'][Keywords::Filter]['optionsRef']);
        $this->assertSame('search', $properties['keywords'][Keywords::Filter]['control']);
    }

    public function test_a_backing_that_declares_nothing_still_gets_the_authorized_empty_vocabulary(): void
    {
        $response = $this->getJson('ticker/filters/schema')->assertOk();

        // `{}` and not `[]` — the declared-empty branch is untouched, object encoding included. Read
        // off the raw body: `->json()` decodes to a PHP array and would erase the very distinction.
        $this->assertStringContainsString('"properties":{}', $response->getContent());
        $this->assertSame('object', $response->json('data.type'));
    }

    public function test_an_unknown_key_still_answers_404(): void
    {
        Particle::filters('nothing-here', at: 'nothing-here');

        $this->getJson('nothing-here/filters/schema')->assertNotFound();
    }

    public function test_competing_backing_and_query_declarations_fail_visibly(): void
    {
        $this->app->make(FilterResourceRegistry::class)->registerDefinition(new FilterResourceDefinition(
            key: 'feed', data: WidgetGateData::class, query: DeclaredResourceQuery::class, model: User::class,
        ));

        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares both backing-owned filters and a data-filters query');
        $this->getJson('feed/filters/schema');
    }

    public function test_options_are_served_for_a_ref_the_declaration_names(): void
    {
        DataFilter::options('feed_sources', fn (?string $search = null) => [
            ['value' => 'circuit', 'label' => 'Circuit'],
            ['value' => 'composition', 'label' => 'Composition'],
        ]);

        $this->getJson('feed/filters/options/feed_sources')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * The options registry is a flat, cross-resource namespace, which is exactly why the flat route
     * leaked. Reaching it through a declaration narrows the read to the refs THAT vocabulary names —
     * a registered ref the declaration never mentions is not this resource's to enumerate.
     */
    public function test_options_404_for_a_ref_the_declaration_does_not_name(): void
    {
        DataFilter::options('silos', fn (?string $search = null) => [['value' => 's', 'label' => 'S']]);

        $this->getJson('feed/filters/options/silos')->assertNotFound();
    }

    /**
     * The Frame resource root is the mount the flagship's FilterPanel actually uses, and it is the one
     * whose route carries TWO parameters. A `string $ref` action parameter received the RESOURCE key
     * there (positional dispatch), so the frame-root options read 404'd on a registered handle while
     * the frozen-resource mount above passed. Both shapes are pinned.
     */
    public function test_options_are_served_at_the_frame_resource_root_where_the_route_carries_two_parameters(): void
    {
        Particle::filters(resource: null, at: 'resources/{resource}', names: 'resources');
        DataFilter::options('feed_sources', fn (?string $search = null) => [['value' => 'circuit', 'label' => 'Circuit']]);

        $this->getJson('resources/feed/filters/schema')->assertOk()->assertJsonPath('data.properties.keywords.x-filter.control', 'search');
        $this->getJson('resources/feed/filters/options/feed_sources')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('resources/feed/filters/options/silos')->assertNotFound();
    }

    public function test_options_404_for_a_backing_that_declares_nothing(): void
    {
        DataFilter::options('feed_sources', fn (?string $search = null) => []);

        $this->getJson('ticker/filters/options/feed_sources')->assertNotFound();
    }
}

class DeclaringFeedBacking implements DeclaresFilterVocabulary, StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }

    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(
            DeclaredFacet::set('source', options: 'feed_sources'),
            DeclaredFacet::set('parentId', options: 'feed_parents'),
            DeclaredFacet::search('keywords'),
        );
    }
}

class SilentFeedBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}
