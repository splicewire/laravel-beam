<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Rendering\Http\RenderingCatalogController;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Tests\Fixtures\Rendering\MirrorRendering;
use Splicewire\Beam\Tests\Fixtures\Rendering\RenderingSubject;
use Splicewire\Beam\Tests\Fixtures\Rendering\TranscriptRendering;
use Splicewire\Beam\Tests\TestCase;

/**
 * The discovery half of `Route::resourceRenderings()` (api-surface-coherence ticket 33): what renderings
 * a resource offers, and in what formats, answerable over the wire instead of by reading config or
 * running `splicewire:beam:manifests`.
 *
 * The sibling of {@see ResourceRenderingsRouteTest}, which covers the read/write routes the same macro
 * call mounts.
 */
class ResourceRenderingCatalogRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RenderingSubject::reset();
        RenderingSubject::seed('doc-1', 'Hello');
        TranscriptRendering::$formats = ['text', 'html'];
    }

    private function renderings(array $renderings, string $resource = 'papers'): ResourceRenderingRegistry
    {
        $registry = app(ResourceRenderingRegistry::class);

        foreach ($renderings as $rendering) {
            $registry->register($resource, $rendering);
        }

        return $registry;
    }

    private function routeNamed(string $name): ?\Illuminate\Routing\Route
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name);
    }

    public function test_publishes_every_rendering_a_resource_offers_with_the_formats_it_accepts(): void
    {
        $this->renderings([new TranscriptRendering, new MirrorRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertSame('papers/renderings', $this->routeNamed('papers.renderings')->uri());

        $this->get('papers/renderings')
            ->assertOk()
            ->assertJsonPath('data.resource', 'papers')
            ->assertJsonPath('data.renderings.0.name', 'transcript')
            ->assertJsonPath('data.renderings.0.formats', ['text', 'html'])
            ->assertJsonPath('data.renderings.1.name', 'mirror');
    }

    /**
     * The three delivery facts ticket 32 added, and the flag that keeps their absence honest. A rendering
     * declining `DeclaresDelivery` publishes empty facts WITH `declaresDelivery: false`, so "has not said"
     * cannot be read as "delivers nothing".
     */
    public function test_publishes_the_delivery_facts_a_rendering_declares_and_flags_the_ones_that_declare_none(): void
    {
        $this->renderings([new TranscriptRendering, new MirrorRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->get('papers/renderings')
            ->assertOk()
            ->assertJsonPath('data.renderings.0.declaresDelivery', true)
            ->assertJsonPath('data.renderings.0.mediaTypes', ['text/plain', 'text/html'])
            ->assertJsonPath('data.renderings.0.defaultFormat', 'text')
            ->assertJsonPath('data.renderings.0.deliveryHeaders.X-Rendering', 'Which rendering produced this body.')
            ->assertJsonPath('data.renderings.1.declaresDelivery', false)
            ->assertJsonPath('data.renderings.1.mediaTypes', [])
            ->assertJsonPath('data.renderings.1.deliveryHeaders', [])
            ->assertJsonPath('data.renderings.1.defaultFormat', null);
    }

    /**
     * The write verb reported is the one MOUNTED, and it comes from the certifier's verdict rather than
     * the rendering's claim — the same frozen grant the read routes carry.
     */
    public function test_reports_the_write_verb_that_is_actually_mounted_and_the_fidelity_behind_it(): void
    {
        $this->renderings([new TranscriptRendering, new MirrorRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNull($this->routeNamed('papers.transcript.ingest'));
        $this->assertNotNull($this->routeNamed('papers.mirror.ingest'));

        $this->get('papers/renderings')
            ->assertOk()
            ->assertJsonPath('data.renderings.0.writable', false)
            ->assertJsonPath('data.renderings.0.fidelity', 'lossy')
            ->assertJsonPath('data.renderings.1.writable', true)
            ->assertJsonPath('data.renderings.1.fidelity', 'lossless-eligible');
    }

    /**
     * An empty `formats()` is a decision, not a gap (ticket 32 §D): one representation, no `?format=`
     * axis. The catalog LISTS such a rendering — it exists and is reachable — and flags the axis off
     * rather than leaving a caller to infer meaning from an empty array.
     */
    public function test_lists_a_rendering_with_no_format_axis_rather_than_omitting_it(): void
    {
        TranscriptRendering::$formats = [];

        $this->renderings([new TranscriptRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->get('papers/renderings')
            ->assertOk()
            ->assertJsonCount(1, 'data.renderings')
            ->assertJsonPath('data.renderings.0.name', 'transcript')
            ->assertJsonPath('data.renderings.0.formats', [])
            ->assertJsonPath('data.renderings.0.hasFormatAxis', false);
    }

    /** Absence of renderings is not absence of resource. */
    public function test_answers_with_an_empty_set_for_a_resource_that_declares_no_renderings(): void
    {
        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->get('papers/renderings')
            ->assertOk()
            ->assertJsonPath('data.resource', 'papers')
            ->assertJsonPath('data.renderings', []);
    }

    /** A resource with no rendering surface has no route here — the 404 is the absent route, not a branch. */
    public function test_a_resource_that_never_mounted_the_macro_has_no_catalog_route_at_all(): void
    {
        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNull($this->routeNamed('journals.renderings'));

        $this->get('journals/renderings')->assertNotFound();
    }

    public function test_mounts_at_the_current_group_root_when_at_is_empty(): void
    {
        $this->renderings([new TranscriptRendering], 'compositions');

        Route::prefix('splice/compositions')->name('splice.compositions.')->group(function () {
            Route::resourceRenderings('compositions', RenderingSubject::class, at: '', abilities: [], idConstraint: 'none');
        });

        $route = $this->routeNamed('splice.compositions.renderings');

        $this->assertNotNull($route);
        $this->assertSame('splice/compositions/renderings', $route->uri());

        $this->get('splice/compositions/renderings')
            ->assertOk()
            ->assertJsonPath('data.renderings.0.name', 'transcript');
    }

    /**
     * The format enumeration is read PER REQUEST; only the verb grant is frozen. A rendering that widens
     * its formats widens what the catalog publishes with no route rebuild.
     */
    public function test_reads_the_format_enumeration_live_rather_than_from_the_route_table(): void
    {
        $this->renderings([new TranscriptRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->get('papers/renderings')->assertJsonPath('data.renderings.0.formats', ['text', 'html']);

        TranscriptRendering::$formats = ['text', 'html', 'markdown'];

        $this->get('papers/renderings')->assertJsonPath('data.renderings.0.formats', ['text', 'html', 'markdown']);
    }

    /** The catalog belongs to its resource on ticket 01's argument, exactly as the rendering routes do. */
    public function test_the_catalog_route_declares_the_resource_it_belongs_to(): void
    {
        $this->renderings([new TranscriptRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertSame('papers', BeamRouteAction::resourceKey($this->routeNamed('papers.renderings')));
    }

    public function test_carries_serializable_defaults_so_the_table_stays_route_cache_safe(): void
    {
        $this->renderings([new TranscriptRendering, new MirrorRendering]);

        Route::resourceRenderings('papers', RenderingSubject::class, idConstraint: 'none');

        $route = $this->routeNamed('papers.renderings');

        $this->assertSame([
            'resource' => 'papers',
            'subject' => RenderingSubject::class,
            'abilities' => ['view' => 'view', 'mutate' => 'update'],
            'renderings' => [
                'transcript' => ['fidelity' => 'lossy', 'writable' => false],
                'mirror' => ['fidelity' => 'lossless-eligible', 'writable' => true],
            ],
        ], $route->defaults[RenderingCatalogController::CONFIG]);

        $this->assertStringStartsWith(RenderingCatalogController::class.'@', $route->getActionName());
        $this->assertIsString(serialize($route->defaults));
    }
}
