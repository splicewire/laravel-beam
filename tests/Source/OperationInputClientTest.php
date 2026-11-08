<?php

namespace Splicewire\Beam\Tests\Source;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 03, the emitter half: a write route's declared input reaches the generated client as its mutation's
 * `body` type, and a route's path parameters reach it as named keys. The input is three-state, as the declaration is:
 * a Data class types the body, `false` sends none, and an undeclared input stays `unknown`.
 */
class OperationInputClientTest extends TestCase
{
    public function test_the_particle_source_carries_each_write_routes_declared_input(): void
    {
        $resources = $this->app->make(ParticleResourceRegistry::class);
        $resources->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget', data: 'App\\Data\\WidgetData', input: 'App\\Data\\WidgetInputData'));
        $resources->register(new ParticleResource(key: 'gadgets', backing: 'App\\Models\\Gadget', data: 'App\\Data\\GadgetData', input: false));
        $resources->register(new ParticleResource(key: 'gizmos', backing: 'App\\Models\\Gizmo', data: 'App\\Data\\GizmoData'));
        $operations = $this->app->make(ParticleOperationRegistry::class);
        foreach (['recalculate' => 'App\\Data\\RecalculateInput', 'poke' => false, 'nudge' => null] as $name => $input) {
            $operations->register(new ParticleOperation(resource: 'widgets', name: $name, kind: OperationKind::Write, model: 'App\\Models\\Widget',
                handle: fn () => null, input: $input, output: 'App\\Data\\WidgetData'));
        }

        Route::prefix('resources')->group(function () {
            Particle::mount('widgets', 'widgets')->only(['index', 'show', 'store', 'update', 'destroy']);
            Particle::mount('gadgets', 'gadgets')->only(['store']);
            Particle::mount('gizmos', 'gizmos')->only(['store']);
            Particle::ops('widgets', 'widgets', 'recalculate');
            Particle::ops('widgets', 'widgets', 'poke');
            Particle::ops('widgets', 'widgets', 'nudge');
        });

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        $this->assertSame('App.Data.WidgetInputData', $manifest['widgets.store']['input']);
        $this->assertSame('App.Data.WidgetInputData', $manifest['widgets.update']['input']);
        $this->assertFalse($manifest['gadgets.store']['input'], 'input: false is carried: the mutation sends no body');
        $this->assertSame('App.Data.RecalculateInput', $manifest['widgets.recalculate']['input']);
        $this->assertFalse($manifest['widgets.poke']['input']);
        foreach (['widgets.index', 'widgets.show', 'widgets.destroy', 'gizmos.store', 'widgets.nudge'] as $name) {
            $this->assertArrayNotHasKey('input', $manifest[$name], "$name: a read, a destroy or an undeclared input carries no input");
        }
    }

    public function test_each_mutation_gets_its_own_variables_typed_from_its_input_and_path(): void
    {
        $hooks = $this->hooks([
            'widgets.show' => ['path' => 'resources/widgets/{id}', 'methods' => ['GET'], 'returns' => 'App.Data.WidgetData'],
            'widgets.store' => ['path' => 'resources/widgets', 'methods' => ['POST'], 'returns' => 'App.Data.WidgetData', 'input' => 'App.Data.WidgetInputData'],
            'widgets.update' => ['path' => 'resources/widgets/{id}', 'methods' => ['PATCH'], 'returns' => 'App.Data.WidgetData', 'input' => 'App.Data.WidgetInputData'],
            'widgets.destroy' => ['path' => 'resources/widgets/{id}', 'methods' => ['DELETE'], 'returns' => 'App.Data.WidgetData'],
            'widgets.poke' => ['path' => 'resources/widgets/{id}/poke', 'methods' => ['POST'], 'returns' => 'App.Data.WidgetData', 'input' => false],
            'widgets.nudge' => ['path' => 'resources/widgets/{id}/nudge', 'methods' => ['POST'], 'returns' => 'App.Data.WidgetData'],
            'widgets.ping' => ['path' => 'resources/widgets/ping', 'methods' => ['POST'], 'returns' => 'App.Data.WidgetData', 'input' => false],
        ])['hooks/widgets.ts'];

        $this->assertStringNotContainsString('MutationVars', $hooks, 'no shared untyped variables type');
        $this->assertStringNotContainsString('Record<string, string | number>', $hooks);
        $this->assertStringContainsString("type MutationOpts<T, V> = Omit<Partial<UseMutationOptions<T, Error, V>>, 'mutationFn'>;", $hooks);

        // A declared input types the body; the path's own parameters are named and required.
        $this->assertStringContainsString('export type WidgetsUpdateVars = { params: { id: string | number }; body: App.Data.WidgetInputData };', $hooks);
        $this->assertStringContainsString('export function useWidgetsUpdate(options?: MutationOpts<App.Data.WidgetData, WidgetsUpdateVars>)', $hooks);
        $this->assertStringContainsString('mutationFn: async (vars: WidgetsUpdateVars) =>', $hooks);
        $this->assertStringContainsString("api.patch(route('widgets.update', vars.params), vars.body)", $hooks);
        $this->assertStringContainsString('export type WidgetsStoreVars = { body: App.Data.WidgetInputData };', $hooks);
        $this->assertStringContainsString("api.post(route('widgets.store'), vars.body)", $hooks);

        // input: false sends no body; an undeclared input stays unknown; a destroy has no body.
        $this->assertStringContainsString('export type WidgetsPokeVars = { params: { id: string | number } };', $hooks);
        $this->assertStringContainsString("api.post(route('widgets.poke', vars.params))", $hooks);
        $this->assertStringContainsString('export type WidgetsNudgeVars = { params: { id: string | number }; body?: unknown };', $hooks);
        $this->assertStringContainsString("api.post(route('widgets.nudge', vars.params), vars.body)", $hooks);
        $this->assertStringContainsString('export type WidgetsDestroyVars = { params: { id: string | number } };', $hooks);
        $this->assertStringContainsString("api.delete(route('widgets.destroy', vars.params))", $hooks);

        // Nothing to send at all: the mutation takes no variables.
        $this->assertStringContainsString('export function useWidgetsPing(options?: MutationOpts<App.Data.WidgetData, void>)', $hooks);
        $this->assertStringContainsString("mutationFn: async () => {\n            const res = await api.post(route('widgets.ping'));", $hooks);
        $this->assertStringNotContainsString('WidgetsPingVars', $hooks);
    }

    public function test_queries_and_streams_take_their_paths_parameters_by_name(): void
    {
        $files = $this->hooks([
            'widgets.parts.show' => ['path' => 'resources/widgets/{widget}/parts/{part}', 'methods' => ['GET'], 'returns' => 'App.Data.PartData'],
            'widgets.watch' => ['path' => 'resources/widgets/{id}/watch', 'methods' => ['POST'], 'streams' => ['tick' => ['App.Data.TickData']]],
        ]);

        $this->assertStringContainsString('export function useWidgetsPartsShow(params: { widget: string | number; part: string | number }, options?: QueryOpts<App.Data.PartData>)', $files['hooks/widgets-parts.ts']);
        $this->assertStringContainsString('export function useWidgetsWatchStream(params: { id: string | number })', $files['hooks/widgets-streams.ts'] ?? implode("\n", $files));
    }

    /** @param array<string, array<string, mixed>> $manifest */
    private function hooks(array $manifest): array
    {
        $source = new class($manifest) implements RouteManifestSource
        {
            public function __construct(private array $manifest) {}

            public function toArray(): array
            {
                return $this->manifest;
            }
        };

        return (new TsClientGenerator)->invoke(['model' => (new RouteManifestModelSource($source))->model()->toArray()])['files'];
    }
}
