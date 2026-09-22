<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\Unpaged;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see Unpaged} — a streams-only backing whose whole population is one page (realm-dashboards 04 review).
 *
 * ⚠️ The paged twin is registered beside it over the SAME rows, so the assertion is a difference: frame's
 * controller cuts the twin at `per_page=1` and leaves the unpaged one whole. A handler that enveloped
 * nothing, or everything, fails one of the two.
 */
class UnpagedBackingTest extends TestCase
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

        // More rows than frame's DEFAULT page (25), so the default request would page the twin.
        $registry->register(new ParticleResource(
            key: 'whole', backing: WholeFeed::class, data: WidgetGateData::class,
            label: 'Whole', readOnly: true, showable: false,
        ));
        $registry->register(new ParticleResource(
            key: 'paged', backing: PagedFeed::class, data: WidgetGateData::class,
            label: 'Paged', readOnly: true,
        ));

        $this->actingAs((new User)->forceFill(['id' => 1]));
    }

    public function test_an_unpaged_backing_lands_every_row_in_one_page_in_the_controllers_own_envelope(): void
    {
        $whole = $this->getJson('frame/resources/whole?per_page=1')->assertOk()->json();

        $this->assertSame(['data', 'total', 'page', 'perPage'], array_keys($whole));
        $this->assertCount(30, $whole['data']);
        $this->assertSame(30, $whole['total']);
        $this->assertSame(1, $whole['page']);
        $this->assertSame(30, $whole['perPage']);
        $this->assertSame(range(1, 30), array_column($whole['data'], 'id'));

        // Same rows, no marker: frame's controller pages them.
        $paged = $this->getJson('frame/resources/paged?per_page=1')->assertOk()->json();
        $this->assertCount(1, $paged['data']);
        $this->assertSame(30, $paged['total']);
        $this->assertSame(1, $paged['perPage']);

        // And with nothing asked, the default page still cuts the twin and not the whole.
        $this->assertCount(30, $this->getJson('frame/resources/whole')->assertOk()->json('data'));
        $this->assertCount(25, $this->getJson('frame/resources/paged')->assertOk()->json('data'));
    }

    public function test_a_streams_only_resource_that_closed_showable_refuses_a_detail_read(): void
    {
        // `showable: false` on a backing that resolves nothing: a 405 refusal, never a 500 from asking the
        // backing for ResolvesRecord.
        $this->getJson('frame/resources/whole/records/3')->assertStatus(405);

        // The paged twin leaves `showable` at its default and resolves nothing either — that is the
        // producer's declaration error, still surfaced as the handler's exception, not masked here.
        $this->assertTrue($this->app->make(ParticleResourceRegistry::class)->find('paged')->showable);
    }
}

class WholeFeed implements Unpaged
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        $rows = array_map(fn (int $id): array => ['id' => $id, 'name' => 'row-'.$id], range(1, 30));

        return new Paginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }
}

class PagedFeed implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        $rows = array_map(fn (int $id): array => ['id' => $id, 'name' => 'row-'.$id], range(1, 30));

        return new Paginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }
}
