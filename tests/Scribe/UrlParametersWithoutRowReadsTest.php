<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately in `App\Models` — that namespace IS the mechanism under test on the second limb.
 * Scribe's `findModelFromUrlThing()` turns the segment in front of an untyped `{id}` into
 * `App\Models\<Thing>` and queries it, so a fixture that lives anywhere else cannot reach that path.
 *
 * `newQuery()` counts instead of querying: the assertion is that the strategy never asks the model
 * for a builder at all, which is a stronger and DB-free statement of "no row was read" than watching
 * a connection.
 */
class RowReadProbe extends Model
{
    public static int $queries = 0;

    protected $table = 'row_read_probes';

    public function newQuery()
    {
        static::$queries++;

        return parent::newQuery();
    }
}

namespace Splicewire\Beam\Tests\Scribe;

use App\Models\RowReadProbe;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Scribe\Strategies\UrlParametersWithoutRowReads;
use Splicewire\Beam\Tests\TestCase;

class RowReadProbeController extends Controller
{
    public function show(RowReadProbe $probe)
    {
        //
    }
}

/**
 * api-surface-coherence ticket 62 — `scribe:generate` must not read a row to fill a path parameter's
 * example.
 *
 * The published artifact is served unauthenticated at `GET beam/openapi.yaml`, so a real primary key
 * written into it as an example is a disclosure, not a cosmetic issue. Scribe's stock
 * `GetFromLaravelAPI` ends its Eloquent inference with `$modelInstance::first()->{$routeKey}`; the
 * replacement keeps the type inference and drops that read.
 *
 * Every test here asserts against BOTH strategies — the stock one as a live control. Without it the
 * suite cannot tell "the read is suppressed" from "the read never happened in this fixture", and
 * that distinction is the whole ticket: the app's own reason for never having leaked turned out to
 * be the fixture (tenant-schema tables absent from the central schema), not a guard.
 */
class UrlParametersWithoutRowReadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RowReadProbe::$queries = 0;
    }

    private function endpoint(string $uri, bool $typeHinted = true): ExtractedEndpointData
    {
        $uses = $typeHinted
            ? RowReadProbeController::class.'@show'
            : NoModelController::class.'@show';

        return ExtractedEndpointData::fromRoute(new Route(['GET'], $uri, [
            'uses' => $uses,
            'controller' => $uses,
        ]));
    }

    public function test_a_type_hinted_binding_reads_no_row(): void
    {
        (new UrlParametersWithoutRowReads(new DocumentationConfig([])))($this->endpoint('probes/{probe}'));

        $this->assertSame(0, RowReadProbe::$queries);
    }

    public function test_the_stock_strategy_does_read_a_row_on_the_same_endpoint(): void
    {
        (new GetFromLaravelAPI(new DocumentationConfig([])))($this->endpoint('probes/{probe}'));

        $this->assertGreaterThan(
            0,
            RowReadProbe::$queries,
            'The control failed: if the stock strategy no longer reads a row here, the replacement is '.
            'no longer suppressing anything and this fixture has stopped exercising the defect.',
        );
    }

    public function test_the_untyped_segment_guess_reads_no_row_either(): void
    {
        // No type hint anywhere — the model is found from the `probes/` segment alone. This limb is
        // the one the ticket did not name, and the dangerous one: it fires on parameters nobody
        // declared anything about, and it reaches the HOST's own `App\Models\*` — where a
        // human-legible primary key is most likely to live.
        (new UrlParametersWithoutRowReads(new DocumentationConfig([])))($this->endpoint('row-read-probes/{id}', typeHinted: false));

        $this->assertSame(0, RowReadProbe::$queries);
    }

    public function test_the_stock_strategy_reads_a_row_on_the_untyped_segment_guess(): void
    {
        (new GetFromLaravelAPI(new DocumentationConfig([])))($this->endpoint('row-read-probes/{id}', typeHinted: false));

        $this->assertGreaterThan(0, RowReadProbe::$queries);
    }

    public function test_type_inference_survives_the_removal(): void
    {
        // The removed line sat at the end of a method whose main job is typing the parameter from the
        // model's key. Losing that alongside the row read would document an int path parameter as a
        // string, so it is asserted rather than assumed: identical types from both strategies.
        $suppressed = (new UrlParametersWithoutRowReads(new DocumentationConfig([])))($this->endpoint('probes/{probe}'));
        $stock = (new GetFromLaravelAPI(new DocumentationConfig([])))($this->endpoint('probes/{probe}'));

        $this->assertSame('integer', $suppressed['probe_id']['type']);
        $this->assertSame($stock['probe_id']['type'], $suppressed['probe_id']['type']);
    }

    public function test_an_example_is_still_produced(): void
    {
        // Suppressing the read must not leave the parameter example-less — the parent's own
        // `setTypesAndExamplesForOthers()` still fills it, and on a Beam host
        // ParticleUrlParameterStrategy overwrites it with a derived value.
        $parameters = (new UrlParametersWithoutRowReads(new DocumentationConfig([])))($this->endpoint('probes/{probe}'));

        $this->assertNotNull($parameters['probe_id']['example']);
    }
}

class NoModelController extends Controller
{
    public function show(string $id)
    {
        //
    }
}
