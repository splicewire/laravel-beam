<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Http\Request;
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
 * between the request and that seam — that `perPage`, `cursor` and the whole `filter[…]` bag reach the
 * composite off the live request, and that the merged rows come back projected through the resource's
 * own `data:` class rather than raw. That plumbing is where a streams-only resource has silently lost a
 * facet before (`filter[parentId]` arriving at a loop that only knew `parent_id`).
 */
class CompositeStreamedIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        ))->registerClass(CompositeProbeResource::class);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function index(array $query = []): array
    {
        $this->app->instance('request', Request::create('/stud/frame/composite-probe', 'GET', $query));

        return $this->app->make(ParticleFrameResourceHandler::class)->index(
            $this->app->make(ParticleResourceRegistry::class)->definition('composite-probe'),
            [],
        );
    }

    /** @return list<string> */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function test_a_composite_backed_resource_takes_the_streamed_path_and_serves_the_merged_order(): void
    {
        $rows = $this->index();

        $this->assertSame(['a5', 'b4', 'a3', 'b2', 'a1'], $this->ids($rows));

        // Projected through the resource's declared `data:` class, not handed back raw.
        $this->assertSame(
            ['id' => 'a5', 'source' => 'alpha', 'label' => 'Alpha five', 'at' => 'T05'],
            $rows[0],
        );
    }

    public function test_per_page_reaches_the_composite_off_the_request(): void
    {
        $this->assertSame(['a5', 'b4'], $this->ids($this->index(['perPage' => 2])));
    }

    public function test_the_cursor_reaches_the_composite_so_page_two_is_the_rows_after_page_one(): void
    {
        // The cursor the backing itself minted for page one — `streamedIndex()` flattens to rows and
        // Frame's socket owns the envelope, so the round trip is asserted where the cursor is minted.
        $cursor = $this->app->make(ProbeComposite::class)
            ->records([], null, 2)
            ->nextCursor()
            ?->encode();

        $this->assertNotNull($cursor);
        $this->assertSame(['a3', 'b2'], $this->ids($this->index(['perPage' => 2, 'cursor' => $cursor])));
    }

    public function test_the_whole_filter_bag_reaches_the_composite_verbatim(): void
    {
        // `source` is the composite's own (it narrows the arm set); `label` is an ARM's, and beam must
        // forward it without knowing what it means.
        $this->assertSame(['b4', 'b2'], $this->ids($this->index(['filter' => ['source' => 'beta']])));
        $this->assertSame(['a5', 'a3', 'a1'], $this->ids($this->index(['filter' => ['label' => 'Alpha']])));
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
    filterable: false,
    label: 'Composite probe',
    readOnly: true,
)]
class CompositeProbeResource extends ArmRowData {}
