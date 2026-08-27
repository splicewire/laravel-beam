<?php

namespace Splicewire\Beam\Tests\Source;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
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
 * The promoted client-SDK generator, exercised against a FAKE {@see RouteManifestSource} — no app routes,
 * no Tower, no particle registry. Proves the source-agnostic seam: give the generator a manifest, it emits
 * `routes.ts` + a typed hook file (a query for reads, a mutation for writes) that imports the configured
 * `@/lib/*` runtimes.
 */
class GenerateClientSdkCommandTest extends TestCase
{
    private string $outDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outDir = sys_get_temp_dir().'/beam-client-sdk-'.uniqid();

        // Bind a fake tenant source; leave admin unbound (satellite one-tier default).
        $this->app->bind(FakeManifestSource::class, fn () => new FakeManifestSource);

        config()->set('beam.client.out_dir', $this->outDir);
        config()->set('beam.client.emit_stores', false);
        config()->set('beam.client.sources.defaults', FakeManifestSource::class);
        config()->set('beam.client.sources.admin', null);
    }

    protected function tearDown(): void
    {
        foreach (['routes.ts', 'aliases.ts', 'hooks/library-lyrics.ts'] as $rel) {
            @unlink($this->outDir.'/'.$rel);
        }
        @rmdir($this->outDir.'/hooks');
        @rmdir($this->outDir.'/stores');
        @rmdir($this->outDir);

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('splicewire:beam:generate:client', $this->app[Kernel::class]->all());
    }

    public function test_it_emits_a_typed_route_map_and_hooks_from_a_fake_source(): void
    {
        $this->artisan('splicewire:beam:generate:client')->assertSuccessful();

        // routes.ts — the RouteMap-typed name→path map. The generator OWNS the RouteMap type it
        // annotates its output with (particle-doctrine-followups #12): the file declares it rather than
        // importing it from the hand-written routes runtime, so no hand-written shape constrains the
        // generator's own emission.
        $routes = file_get_contents($this->outDir.'/routes.ts');
        $this->assertStringNotContainsString("import type { RouteMap } from '@/lib/routes';", $routes);
        $this->assertStringContainsString('export type RouteMap = Record<string, string>;', $routes);
        $this->assertStringContainsString('export const defaults: RouteMap = {', $routes);
        $this->assertStringContainsString("'library-lyrics.index': 'resources/library-lyrics',", $routes);
        $this->assertStringContainsString("'library-lyrics.update': 'resources/library-lyrics/{id}',", $routes);
        // No operator source bound ⇒ an empty operatorDefaults map, never absent.
        $this->assertStringContainsString('export const operatorDefaults: RouteMap = {', $routes);

        // hooks/library-lyrics.ts — a query for the read, a mutation for the write.
        $hook = file_get_contents($this->outDir.'/hooks/library-lyrics.ts');
        $this->assertStringContainsString("import { useMutation, useQuery, type UseQueryOptions, type UseMutationOptions } from '@tanstack/react-query';", $hook);
        $this->assertStringContainsString("import { api } from '@/lib/api';", $hook);
        $this->assertStringContainsString("import { route } from '@/lib/routes';", $hook);

        // The index read → useQuery yielding the list type.
        $this->assertStringContainsString('export function useLibraryLyricsIndex(', $hook);
        $this->assertStringContainsString('return res.data.data as App.Data.Library.LyricPieceProjectData[];', $hook);

        // The update write → useMutation with params + body, PATCH verb.
        $this->assertStringContainsString('export function useLibraryLyricsUpdate(', $hook);
        $this->assertStringContainsString("api.patch(route('library-lyrics.update', vars.params ?? {}), vars.body)", $hook);

        // aliases.ts — the friendly non-namespaced re-export.
        $aliases = file_get_contents($this->outDir.'/aliases.ts');
        $this->assertStringContainsString('export type LyricPieceProjectData = App.Data.Library.LyricPieceProjectData;', $aliases);
    }

    public function test_stores_are_off_by_default(): void
    {
        $this->artisan('splicewire:beam:generate:client')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->outDir.'/stores');
    }

    public function test_the_particle_source_reads_mounted_routes_and_derives_returns_from_the_output_dto(): void
    {
        // Register a resource whose output DTO is an explicit read Data class, then MOUNT its CRUD routes
        // via the macro under a `resources/` prefix — exactly how a satellite provider wires them.
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: 'App\\Data\\WidgetData',
        ));

        Route::prefix('resources')->group(function () {
            Particle::mount('widgets', 'widgets')->only(['index', 'update']);
        });

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        // The prefix is READ off the live table (not hardcoded), path is slash-stripped, params templated.
        $this->assertSame('resources/widgets', $manifest['widgets.index']['path']);
        $this->assertSame('resources/widgets/{id}', $manifest['widgets.update']['path']);

        // `returns` is derived from the resource's output DTO → its TS type path; index is a collection.
        $this->assertSame('App.Data.WidgetData', $manifest['widgets.index']['returns']);
        $this->assertTrue($manifest['widgets.index']['returnsMany']);
        $this->assertSame('App.Data.WidgetData', $manifest['widgets.update']['returns']);
        $this->assertArrayNotHasKey('returnsMany', $manifest['widgets.update']);
        // The unified macro widens update to PUT+PATCH (absorbing the app's convention), so a legacy CRUD
        // controller can be dissolved onto it without dropping the PUT verb its clients used.
        $this->assertSame(['PUT', 'PATCH'], $manifest['widgets.update']['methods']);
    }

    public function test_the_particle_source_admits_operation_routes_alongside_resource_routes(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: 'App\\Data\\WidgetData',
        ));

        Route::prefix('resources')->group(function () {
            Particle::mount('widgets', 'widgets')->only(['index']);
            Particle::ops('widgets', 'widgets', 'recalculate');
        });

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        // The op route is no longer excluded: it reaches the manifest, so the generator can cover it.
        $this->assertArrayHasKey('widgets.op.recalculate', $manifest);
        $this->assertSame('resources/widgets/{id}/op/recalculate', $manifest['widgets.op.recalculate']['path']);
        $this->assertSame(['POST'], $manifest['widgets.op.recalculate']['methods']);

        // Resource entries are untouched by the widening.
        $this->assertSame('App.Data.WidgetData', $manifest['widgets.index']['returns']);
        $this->assertTrue($manifest['widgets.index']['returnsMany']);
    }

    public function test_an_operation_route_resolves_its_return_type_from_its_declared_output_slot(): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'widgets',
            name: 'recalculate',
            kind: OperationKind::Write,
            model: 'App\\Models\\Widget',
            handle: fn () => null,
            output: 'App\\Data\\WidgetTotalsData',
        ));

        Route::prefix('resources')->group(fn () => Particle::ops('widgets', 'widgets', 'recalculate'));

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();
        $entry = $manifest['widgets.op.recalculate'];

        // No per-route annotation: the op's own declaration is what types the generated hook.
        $this->assertSame('App.Data.WidgetTotalsData', $entry['returns']);
        $this->assertArrayNotHasKey('returnsMany', $entry);
        $this->assertArrayNotHasKey('unresolved', $entry);
    }

    public function test_an_operation_declaring_no_output_slot_is_recorded_as_unresolved(): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'widgets',
            name: 'poke',
            kind: OperationKind::Write,
            model: 'App\\Models\\Widget',
            handle: fn () => null,
        ));

        Route::prefix('resources')->group(fn () => Particle::ops('widgets', 'widgets', 'poke'));

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        $this->assertArrayNotHasKey('returns', $manifest['widgets.op.poke']);
        $this->assertTrue($manifest['widgets.op.poke']['unresolved']);
    }

    public function test_a_stream_operations_event_map_is_emitted_rather_than_a_single_return_type(): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'widgets',
            name: 'watch',
            kind: OperationKind::Stream,
            model: 'App\\Models\\Widget',
            handle: fn () => null,
            output: [
                'run_status' => ['App\\Data\\RunStatusData'],
                'node_status' => ['App\\Data\\NodeRunningData', 'App\\Data\\NodeCompleteData'],
            ],
        ));

        Route::prefix('resources')->group(fn () => Particle::ops('widgets', 'widgets', 'watch'));

        $entry = $this->app->make(ParticleRouteManifestSource::class)->toArray()['widgets.op.watch'];

        // A stream declares a SEQUENCE of typed events, so it carries `streams`, never `returns` — and it is
        // a declaration, so it is not negative space.
        $this->assertArrayNotHasKey('returns', $entry);
        $this->assertArrayNotHasKey('unresolved', $entry);
        $this->assertSame([
            'run_status' => ['App.Data.RunStatusData'],
            'node_status' => ['App.Data.NodeRunningData', 'App.Data.NodeCompleteData'],
        ], $entry['streams']);
    }

    public function test_an_unresolvable_return_type_is_recorded_rather_than_silently_absent(): void
    {
        // A resource key that was never registered — the degradation is CORRECT (never fabricate a type)
        // but it must be reportable, else "we generated nothing" and "this returns nothing" look identical.
        Route::prefix('resources')->group(function () {
            Particle::mount('ghosts', 'ghosts')->only(['index']);
            Particle::ops('ghosts', 'ghosts', 'vanish');
        });

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        foreach (['ghosts.index', 'ghosts.op.vanish'] as $name) {
            $this->assertArrayNotHasKey('returns', $manifest[$name], "[{$name}] must not fabricate a type.");
            $this->assertTrue($manifest[$name]['unresolved'], "[{$name}] must record its unresolved shape.");
        }
    }

    public function test_a_resolved_entry_is_not_marked_unresolved(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: 'App\\Data\\WidgetData',
        ));

        Route::prefix('resources')->group(fn () => Particle::mount('widgets', 'widgets')->only(['index']));

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        $this->assertArrayNotHasKey('unresolved', $manifest['widgets.index']);
    }
}

/**
 * A hand-built manifest source standing in for the particle/Tower sources — two read entries and one write,
 * one carrying `returns`/`returnsMany`, so the generator has a query, a list, and a mutation to render.
 */
class FakeManifestSource implements RouteManifestSource
{
    public function toArray(): array
    {
        return [
            'library-lyrics.index' => [
                'path' => 'resources/library-lyrics',
                'methods' => ['GET'],
                'returns' => 'App.Data.Library.LyricPieceProjectData',
                'returnsMany' => true,
            ],
            'library-lyrics.update' => [
                'path' => 'resources/library-lyrics/{id}',
                'methods' => ['PATCH'],
                'returns' => 'App.Data.Library.LyricPieceProjectData',
            ],
        ];
    }
}
