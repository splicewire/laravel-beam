<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Graph\Data\GraphSliceData;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Tests\Codegen\Fixtures\InheritedPropertiesInput;
use Splicewire\Beam\Tests\Codegen\Fixtures\PropertylessInput;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphWidgetBacking;
use Splicewire\Beam\Tests\TestCase;

/**
 * owner-review-20261112 53: a read op whose input Data declares no properties (laravel-beam-ux's
 * `EntryComponentCapabilitiesInputData`) is transformed to `object`, which `beamQuery(input: Record<string, unknown>)`
 * rejects (TS2345). It generates the no-input shape instead: no `input` argument and no `beamQuery`, on the hook
 * and on the store. A subclass that only INHERITS its properties still carries its input.
 */
class PropertylessReadInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('graph_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_a_read_op_whose_input_declares_no_properties_generates_the_no_input_shape(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'graph-widgets',
            backing: GraphWidgetBacking::class,
            readOnly: true,
        ));
        foreach (['empty-probe' => PropertylessInput::class, 'inherited-probe' => InheritedPropertiesInput::class] as $name => $input) {
            $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
                resource: 'graph-widgets',
                name: $name,
                kind: OperationKind::Read,
                handle: fn () => ['data' => []],
                input: $input,
                output: GraphSliceData::class,
                subject: NoSubject::class,
                method: HttpMethod::Get,
            ));
        }
        Particle::mount('graph-widgets')->ops(true)->register();

        $source = $this->app->make(ParticleRouteManifestSource::class);
        $manifest = $source->toArray();
        $this->assertFalse($manifest['graph-widgets.empty-probe']['input'], 'a property-less read input is recorded as sending none');
        $this->assertStringContainsString('InheritedPropertiesInput', (string) $manifest['graph-widgets.inherited-probe']['input']);

        $files = (new TsClientGenerator)->invoke([
            'model' => (new RouteManifestModelSource($source))->model()->toArray(),
            'options' => ['emit_stores' => true],
        ])['files'];
        $generated = implode("\n\n", $files);

        $empty = $this->block($generated, '/export function use\w*EmptyProbe\(.*?\n}\n/s');
        $this->assertStringNotContainsString('input', $empty);
        $this->assertStringNotContainsString('beamQuery', $empty);

        $emptyStore = $this->block($generated, '/type \w*EmptyProbeState = \{.*?\n}\)\);\n/s');
        $this->assertStringContainsString('fetch: () => Promise<void>;', $emptyStore);
        $this->assertStringNotContainsString('beamQuery', $emptyStore);

        // The control: properties inherited from a property-bearing Data keep the typed, query-serialized input.
        $inherited = $this->block($generated, '/export function use\w*InheritedProbe\(.*?\n}\n/s');
        $this->assertMatchesRegularExpression('/input:\s*[\w.]*InheritedPropertiesInput/', $inherited);
        $this->assertStringContainsString('+ beamQuery(input))', $inherited);
    }

    private function block(string $generated, string $pattern): string
    {
        $this->assertMatchesRegularExpression($pattern, $generated);
        preg_match($pattern, $generated, $match);

        return $match[0];
    }
}
