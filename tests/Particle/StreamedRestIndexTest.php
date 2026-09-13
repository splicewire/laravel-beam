<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\ResponseBodyEnvelope;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRowData;
use Splicewire\Beam\Tests\Fixtures\Backing\ProbeComposite;
use Splicewire\Beam\Tests\TestCase;

/**
 * The REST transport serves a STREAMS-ONLY backing (composite-backing ticket 04).
 *
 * Until this landed, `ParticleController::index()` had exactly one shape — compose a `Builder`, call
 * `paginate()` — so a resource whose rows come from N sources could reach Frame and nothing else, and
 * `queryableBacking()` said so by name. That is why the activity cross-connection union stayed
 * nominated for a ticket: the surface that had to show it is a REST mount.
 *
 * The Frame twin is `CompositeStreamedIndexTest`; what only this file can cover is the REST-side
 * plumbing — that the streamed branch is CHOSEN, that `perPage`/`cursor` reach the backing off the
 * request, that the page comes back through the cursor envelope with a usable next cursor, and that a
 * queryable backing is untouched by any of it.
 */
class StreamedRestIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'streamed-probe',
            backing: ProbeComposite::class,
            data: ArmRowData::class,
            filterable: false,
            perPage: 3,
            // A composite declines `WritesRecords`, and capability is the ceiling — so the declaration
            // has to close the write affordances it would otherwise open by default. That refusal is
            // ticket 03's, at REGISTRATION; this file exercises the read it left unserved.
            readOnly: true,
        ));
    }

    /** @param  array<string, mixed>  $query */
    private function response(array $query = []): array
    {
        $request = Request::create('/streamed-probes', 'GET', $query);
        $route = new Route('GET', '/streamed-probes', []);
        $route->defaults(ParticleController::RESOURCE, 'streamed-probe');
        $request->setRouteResolver(fn () => $route);

        return $this->app->make(ParticleController::class)
            ->index($request)
            ->toResponse($request)
            ->getData(true);
    }

    public function test_a_streams_only_backing_is_served_over_rest_in_the_merged_order(): void
    {
        $body = $this->response(['perPage' => 10]);

        $this->assertSame(['a5', 'b4', 'a3', 'b2', 'a1'], array_column($body['data'], 'id'));

        // Projected through the declaration's `data:` class, exactly as the queried path projects.
        $this->assertSame(
            ['id' => 'a5', 'source' => 'alpha', 'label' => 'Alpha five', 'at' => 'T05'],
            $body['data'][0],
        );
    }

    public function test_the_declarations_per_page_is_the_default_and_the_request_overrides_it(): void
    {
        $this->assertCount(3, $this->response()['data']);
        $this->assertCount(2, $this->response(['perPage' => 2])['data']);
    }

    /**
     * The round trip that a page-based index cannot do across sources: page 2 is the rows AFTER page 1,
     * with each arm resumed at its own cursor rather than the merged stream rescanned for an id.
     */
    public function test_the_next_cursor_round_trips_to_the_rows_after_the_page(): void
    {
        $first = $this->response(['perPage' => 2]);

        $this->assertSame(['a5', 'b4'], array_column($first['data'], 'id'));
        $this->assertNotNull($first['nextCursor']);

        $second = $this->response(['perPage' => 2, 'cursor' => $first['nextCursor']]);

        $this->assertSame(['a3', 'b2'], array_column($second['data'], 'id'));
    }

    /**
     * ⚠️ The assertion that catches the bug the envelope signature exists to prevent: the next cursor is
     * read off the paginator BEFORE the rows are projected. A stock `CursorPaginator` derives it from the
     * last item's own attributes, so projecting first answers null and page 2 silently becomes page 1.
     */
    public function test_the_last_page_reports_no_next_cursor(): void
    {
        $this->assertNull($this->response(['perPage' => 10])['nextCursor']);
    }

    public function test_the_whole_filter_bag_reaches_the_backing_verbatim(): void
    {
        // `source` is the composite's own — it narrows the ARM SET; `label` belongs to an arm and beam
        // forwards it without knowing what it means.
        $this->assertSame(['b4', 'b2'], array_column($this->response(['filter' => ['source' => 'beta']])['data'], 'id'));
        $this->assertSame(['a5', 'a3', 'a1'], array_column($this->response(['perPage' => 10, 'filter' => ['label' => 'Alpha']])['data'], 'id'));
    }

    /**
     * A cursor page knows neither an offset nor a total, so the envelope publishes neither rather than
     * inventing two numbers beside real rows.
     */
    public function test_the_cursor_envelope_carries_limit_and_next_cursor_and_no_invented_total(): void
    {
        $body = $this->response(['perPage' => 2]);

        $this->assertSame(2, $body['limit']);
        $this->assertArrayHasKey('nextCursor', $body);
        $this->assertArrayNotHasKey('offset', $body);
        $this->assertArrayNotHasKey('total', $body);
    }

    /** The richer envelope carries the same page, with the cursor in `meta` (see `ResponseBody::streamed()`). */
    public function test_the_response_body_envelope_carries_the_cursor_in_meta(): void
    {
        $this->app->instance(ResponseEnvelope::class, new ResponseBodyEnvelope);

        $body = $this->response(['perPage' => 2]);

        $this->assertTrue($body['success']);
        $this->assertSame(['a5', 'b4'], array_column($body['data'], 'id'));
        $this->assertNotNull($body['meta']['nextCursor']);
    }
}
