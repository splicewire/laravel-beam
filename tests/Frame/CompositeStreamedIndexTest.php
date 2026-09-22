<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Foundation\Auth\User;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Backing\CompositeBacking;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRowData;
use Splicewire\Beam\Tests\Fixtures\Backing\ProbeComposite;
use Splicewire\Beam\Tests\TestCase;

/**
 * The composite over the FRAME transport, end to end: an attribute-declared resource whose `backing:` is
 * a composite class-string, served through {@see ParticleFrameResourceHandler::index()} → `streamedIndex()`.
 *
 * The backing seam is covered by `CompositeBackingTest`; what only this test can cover is the PLUMBING
 * between the request and that seam — that `per_page`, `cursor` and the whole `filter[…]` bag reach the
 * composite off the live request, and that the merged rows come back projected through the resource's
 * own `data:` class rather than raw. That plumbing is where a streams-only resource has silently lost a
 * facet before (`filter[parentId]` arriving at a loop that only knew `parent_id`).
 */
class CompositeStreamedIndexTest extends TestCase
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

        (new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        ))->registerClass(CompositeProbeResource::class);
        $this->actingAs((new User)->forceFill(['id' => 1]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function index(array $query = []): array
    {
        return $this->getJson('/frame/resources/composite-probe?'.http_build_query($query))
            ->assertOk()->json();
    }

    /** @return list<string> */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function test_a_composite_backed_resource_takes_the_streamed_path_and_serves_the_merged_order(): void
    {
        $rows = $this->index()['data'];

        $this->assertSame(['a5', 'b4', 'a3', 'b2', 'a1'], $this->ids($rows));

        // Projected through the resource's declared `data:` class, not handed back raw.
        $this->assertSame(
            ['id' => 'a5', 'source' => 'alpha', 'label' => 'Alpha five', 'at' => 'T05'],
            $rows[0],
        );
    }

    public function test_per_page_reaches_the_composite_off_the_request(): void
    {
        $page = $this->index(['per_page' => 2]);
        $this->assertSame(['a5', 'b4'], $this->ids($page['data']));
        $this->assertSame(2, $page['perPage']);
    }

    public function test_the_cursor_reaches_the_composite_so_page_two_is_the_rows_after_page_one(): void
    {
        $first = $this->index(['per_page' => 2]);
        $this->assertArrayHasKey('nextCursor', $first);
        $this->assertIsString($first['nextCursor']);
        $second = $this->index(['per_page' => 2, 'cursor' => $first['nextCursor']]);
        $this->assertSame(['a3', 'b2'], $this->ids($second['data']));
        $this->assertSame([], array_intersect($this->ids($first['data']), $this->ids($second['data'])));
        $this->assertIsString($second['nextCursor']);
        $last = $this->index(['per_page' => 2, 'cursor' => $second['nextCursor']]);
        $this->assertSame(['a1'], $this->ids($last['data']));
        $this->assertSame(['data' => $last['data'], 'perPage' => 2, 'nextCursor' => null], $last);
    }

    public function test_the_whole_filter_bag_reaches_the_composite_verbatim(): void
    {
        // `source` is the composite's own (it narrows the arm set); `label` is an ARM's, and beam must
        // forward it without knowing what it means.
        $this->assertSame(['b4', 'b2'], $this->ids($this->index(['filter' => ['source' => 'beta']])['data']));
        $this->assertSame(['a5', 'a3', 'a1'], $this->ids($this->index(['filter' => ['label' => 'Alpha']])['data']));

        $query = ['per_page' => 1, 'filter' => ['source' => 'beta']];
        $first = $this->index($query);
        $this->assertArrayHasKey('nextCursor', $first);
        $second = $this->index([...$query, 'cursor' => $first['nextCursor']]);
        $this->assertSame(['b2'], $this->ids($second['data']));
        $this->assertNull($second['nextCursor']);
    }

    public function test_a_denied_resource_cannot_replay_an_otherwise_valid_cursor(): void
    {
        $access = new class implements ResourceAccessGate
        {
            public bool $allowed = true;

            public function allowsResource(ResourceDefinition $definition): bool
            {
                return $this->allowed;
            }
        };
        $this->app->instance(ResourceAccessGate::class, $access);
        $first = $this->index(['per_page' => 1]);
        $this->assertArrayHasKey('nextCursor', $first);
        $access->allowed = false;

        $this->getJson('/frame/resources/composite-probe?'.http_build_query([
            'per_page' => 1, 'cursor' => $first['nextCursor'],
        ]))->assertForbidden();
    }

    public function test_the_declaration_resolves_to_a_composite_the_container_built(): void
    {
        $backing = $this->app->make(ParticleResourceRegistry::class)->get('composite-probe')->backing();

        $this->assertInstanceOf(CompositeBacking::class, $backing);
        $this->assertInstanceOf(ProbeComposite::class, $backing);
    }
}

#[ParticleResource(
    key: 'composite-probe',
    backing: ProbeComposite::class,
    data: ArmRowData::class,
    label: 'Composite probe',
    readOnly: true,
)]
class CompositeProbeResource extends ArmRowData {}
