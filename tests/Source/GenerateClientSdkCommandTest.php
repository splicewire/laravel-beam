<?php

namespace Splicewire\Beam\Tests\Source;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
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

        // routes.ts — the RouteMap-typed name→path map, importing the configured routes runtime.
        $routes = file_get_contents($this->outDir.'/routes.ts');
        $this->assertStringContainsString("import type { RouteMap } from '@/lib/routes';", $routes);
        $this->assertStringContainsString('export const defaults: RouteMap = {', $routes);
        $this->assertStringContainsString("'library-lyrics.index': 'resources/library-lyrics',", $routes);
        $this->assertStringContainsString("'library-lyrics.update': 'resources/library-lyrics/{id}',", $routes);
        // No admin source bound ⇒ an empty adminDefaults map, never absent.
        $this->assertStringContainsString('export const adminDefaults: RouteMap = {', $routes);

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
            model: 'App\\Models\\Widget',
            data: 'App\\Data\\WidgetData',
        ));

        Route::prefix('resources')->group(function () {
            Route::particleResource('widgets', 'widgets', ['only' => ['index', 'update']]);
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
