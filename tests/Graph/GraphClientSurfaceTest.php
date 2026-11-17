<?php

namespace Splicewire\Beam\Tests\Graph;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphWidgetBacking;
use Splicewire\Beam\Tests\Graph\Fixtures\PlainWidget;
use Splicewire\Beam\Tests\TestCase;

/**
 * graph-operations 02, done-when 6 — **the generated client carries the four with their input and output
 * types.** Measured through the CLIENT MANIFEST ({@see ParticleRouteManifestSource::toArray()}) — the one
 * the generator consumes (review B1): each of the four op routes carries its `input` (the query/params
 * type, since a GET op's input is not a body) AND its `returns` (the output type). A non-capability
 * resource carries none — the negative control.
 *
 * (The full TypeScript compile of the emitted client is beam's env-gated `tsc` surface
 * ({@see \Splicewire\Beam\Tests\Codegen\ClientTypeReferencesTest}, skipped without `BEAM_TYPESCRIPT_BIN`);
 * the four wire shapes are ordinary spatie Data the suite's static analysis already type-checks.)
 */
class GraphClientSurfaceTest extends TestCase
{
    /** @var array<string, array{0: string, 1: string}> op route name => [input short name, output short name] */
    private const EXPECTED = [
        'graph-widgets.graph' => ['GraphSliceInput', 'GraphSliceData'],
        'graph-widgets.graph.cycles' => ['GraphCyclesInput', 'GraphCyclesData'],
        'graph-widgets.graph.neighbours' => ['GraphNeighboursInput', 'GraphSliceData'],
        'graph-widgets.graph.path' => ['GraphPathInput', 'GraphPathData'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('graph_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_the_client_manifest_carries_the_four_with_their_input_and_output_types(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'graph-widgets',
            backing: GraphWidgetBacking::class,
            readOnly: true,
        ));
        Particle::mount('graph-widgets')->ops(true)->register();

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        foreach (self::EXPECTED as $name => [$input, $output]) {
            $this->assertArrayHasKey($name, $manifest, "{$name} reaches the client manifest");
            $this->assertArrayHasKey('input', $manifest[$name], "{$name} carries an input type (a GET op's query/params type)");
            $this->assertStringContainsString($input, (string) $manifest[$name]['input'], "{$name} input is {$input}");
            $this->assertStringContainsString($output, (string) $manifest[$name]['returns'], "{$name} returns {$output}");
        }
    }

    public function test_the_generated_read_hook_types_a_graph_op_input_and_sends_it_as_query(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'graph-widgets',
            backing: GraphWidgetBacking::class,
            readOnly: true,
        ));
        // A control: a read op that declares NO input — its hook must stay a plain `get`, byte-identical.
        $this->app->make(ParticleOperationRegistry::class)->register(new \Splicewire\Beam\Particle\ParticleOperation(
            resource: 'graph-widgets',
            name: 'ping',
            kind: \Splicewire\Beam\Particle\OperationKind::Read,
            handle: fn () => ['data' => []],
            input: false,
            output: \Splicewire\Beam\Graph\Data\GraphSliceData::class, // a return type, so the read generates a hook
            subject: \Splicewire\Beam\Particle\Subject\NoSubject::class,
            method: \Splicewire\Beam\Routing\HttpMethod::Get,
        ));
        Particle::mount('graph-widgets')->ops(true)->register();

        // The real pipeline: live routes → manifest → client model → generated hooks (review B1 r2).
        $source = $this->app->make(ParticleRouteManifestSource::class);
        $files = (new TsClientGenerator)->invoke([
            'model' => (new RouteManifestModelSource($source))->model()->toArray(),
            'options' => ['emit_stores' => true],
        ])['files'];
        $hooks = implode("\n\n", array_filter(
            $files,
            fn (string $path): bool => str_starts_with($path, 'hooks/'),
            ARRAY_FILTER_USE_KEY,
        ));

        // The serializer is emitted ONCE and imported where a read declares an input.
        $this->assertArrayHasKey('query.ts', $files);
        $this->assertStringContainsString('export function beamQuery(', $files['query.ts']);
        $this->assertStringContainsString("import { beamQuery } from '../query';", $hooks);

        // A GET op WITH a declared input: the generated read hook TYPES the input and builds the QUERY into
        // the URL — `api.get(route(...) + beamQuery(input))` — since the host `api.get(url)` takes one
        // argument and `route()` appends no query (the r3 blocker). The URL expression, not a 2nd arg.
        $this->assertMatchesRegularExpression('/input:\s*[\w.]*GraphNeighboursInput/', $hooks);
        $this->assertStringContainsString('+ beamQuery(input))', $hooks); // appended to the URL, not a 2nd arg
        $this->assertStringNotContainsString(', input)', $hooks);         // never the invalid 2nd-argument form

        // A read WITHOUT a declared input stays a plain `get` with NO query (byte-identical): with the
        // `ping` control there are strictly MORE query hooks than beamQuery-appending gets.
        $this->assertGreaterThan(
            substr_count($hooks, '+ beamQuery(input))'),
            substr_count($hooks, 'useQuery('),
            'a read without a declared input (the ping control) must send a plain get with no query',
        );
    }

    public function test_a_resource_without_the_capability_carries_none_of_the_four_in_the_client(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'plain-widgets',
            backing: PlainWidget::class,
            readOnly: true,
        ));
        Particle::mount('plain-widgets')->ops(true)->register();

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        foreach (['graph', 'graph.cycles', 'graph.neighbours', 'graph.path'] as $name) {
            $this->assertArrayNotHasKey("plain-widgets.{$name}", $manifest, "plain-widgets.{$name} must not reach the client");
        }

        $this->assertSame([], $this->app->make(ParticleOperationRegistry::class)->forResource('plain-widgets'));
    }
}
