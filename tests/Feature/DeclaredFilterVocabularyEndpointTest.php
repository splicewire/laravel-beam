<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Keywords;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Filters\DeclaredResourceQuery;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/** Frame metadata serves declared backing vocabularies and rejects competing query declarations. */
class DeclaredFilterVocabularyEndpointTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(ParticleResourceRegistry::class);

        $registry->register(new ParticleResource(
            key: 'feed',
            backing: DeclaringFeedBacking::class,
            data: WidgetGateData::class,
            frame: true,
            readOnly: true,
            showable: false,
        ));
        $registry->register(new ParticleResource(
            key: 'ticker',
            backing: SilentFeedBacking::class,
            data: WidgetGateData::class,
            frame: true,
            readOnly: true,
            showable: false,
        ));

    }

    public function test_a_declaring_backing_is_served_its_declared_vocabulary(): void
    {
        $properties = $this->getJson('frame/resources/feed/filters/schema')
            ->assertOk()
            ->json('data.properties');

        $this->assertSame(['source', 'parentId', 'keywords'], array_keys($properties));
        $this->assertSame('multiselect', $properties['source'][Keywords::Filter]['control']);
        $this->assertSame('feed_sources', $properties['source'][Keywords::Filter]['optionsRef']);
        $this->assertSame('search', $properties['keywords'][Keywords::Filter]['control']);
    }

    public function test_a_backing_that_declares_nothing_still_gets_the_authorized_empty_vocabulary(): void
    {
        $response = $this->getJson('frame/resources/ticker/filters/schema')->assertOk();

        // `{}` and not `[]` — the declared-empty branch is untouched, object encoding included. Read
        // off the raw body: `->json()` decodes to a PHP array and would erase the very distinction.
        $this->assertStringContainsString('"properties":{}', $response->getContent());
        $this->assertSame('object', $response->json('data.type'));
    }

    public function test_an_unknown_key_still_answers_404(): void
    {

        $this->getJson('frame/resources/nothing-here/filters/schema')->assertNotFound();
    }

    public function test_competing_backing_and_query_declarations_fail_visibly(): void
    {
        $this->app->make(FilterResourceRegistry::class)->registerDefinition(new FilterResourceDefinition(
            key: 'feed', data: WidgetGateData::class, query: DeclaredResourceQuery::class, model: User::class,
        ));

        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares both backing-owned filters and a data-filters query');
        $this->getJson('frame/resources/feed/filters/schema');
    }

    public function test_options_are_served_for_a_ref_the_declaration_names(): void
    {
        DataFilter::options('feed_sources', fn (?string $search = null) => [
            ['value' => 'circuit', 'label' => 'Circuit'],
            ['value' => 'composition', 'label' => 'Composition'],
        ]);

        $this->getJson('frame/resources/feed/filters/options/feed_sources')
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

        $this->getJson('frame/resources/feed/filters/options/silos')->assertNotFound();
    }

    /**
     * The Frame resource root is the mount the flagship's FilterPanel actually uses, and it is the one
     * whose route carries TWO parameters. A `string $ref` action parameter received the RESOURCE key
     * there (positional dispatch), so the frame-root options read 404'd on a registered handle while
     * the frozen-resource mount above passed. Both shapes are pinned.
     */
    public function test_options_are_served_at_the_frame_resource_root_where_the_route_carries_two_parameters(): void
    {
        DataFilter::options('feed_sources', fn (?string $search = null) => [['value' => 'circuit', 'label' => 'Circuit']]);

        $this->getJson('frame/resources/feed/filters/schema')->assertOk()->assertJsonPath('data.properties.keywords.x-filter.control', 'search');
        $this->getJson('frame/resources/feed/filters/options/feed_sources')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('frame/resources/feed/filters/options/silos')->assertNotFound();
    }

    public function test_options_404_for_a_backing_that_declares_nothing(): void
    {
        DataFilter::options('feed_sources', fn (?string $search = null) => []);

        $this->getJson('frame/resources/ticker/filters/options/feed_sources')->assertNotFound();
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
