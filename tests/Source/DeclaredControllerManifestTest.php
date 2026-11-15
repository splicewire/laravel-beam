<?php

namespace Splicewire\Beam\Tests\Source;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Codegen\DeclaredRouteTypes;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteVisibility;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Versioning\BeamVersioningServiceProvider;
use Splicewire\Beam\Versioning\Data\VersionData;
use Splicewire\Beam\Versioning\Data\VersionInputData;
use Splicewire\Beam\Versioning\Data\VersionListResponseData;
use Splicewire\Beam\Versioning\Data\VersionResponseData;

class DeclaredControllerManifestTest extends TestCase
{
    public function test_the_real_record_versions_triplet_reaches_the_manifest_and_model_under_a_group(): void
    {
        $this->app->register(BeamVersioningServiceProvider::class);
        Route::prefix('tenant/runtime')->name('tenant.')->middleware('auth')->group(function () {
            Route::recordVersions('timeline-projects', \Illuminate\Database\Eloquent\Model::class, data: VersionData::class);
        });
        Route::getRoutes()->refreshNameLookups();

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();
        $names = ['tenant.timeline-projects.versions.index', 'tenant.timeline-projects.versions.store', 'tenant.timeline-projects.versions.restore'];
        $found = array_values(array_filter(array_keys($manifest), fn ($name) => str_starts_with($name, 'tenant.timeline-projects.versions.')));
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $found);

        foreach (['index' => 'GET', 'store' => 'POST', 'restore' => 'POST'] as $verb => $method) {
            $name = 'tenant.timeline-projects.versions.'.$verb;
            $entry = $manifest[$name];
            $this->assertSame('tenant/runtime/timeline-projects/{id}/versions'.($verb === 'restore' ? '/{ref}/restore' : ''), $entry['path']);
            $this->assertSame([$method], $entry['methods']);
            $this->assertSame(str_replace('\\', '.', $verb === 'index' ? VersionListResponseData::class : VersionResponseData::class), $entry['returns']);
            $this->assertTrue($entry['returnsBody']);
            $this->assertFalse($entry['returnsMany'] ?? false, 'The list is inside the HTTP body, not a list of response bodies.');
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame($verb === 'restore' ? ['id', 'ref'] : ['id'], $route->parameterNames());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertArrayHasKey('id', $route->wheres);
            if ($method === 'POST') {
                $this->assertSame(str_replace('\\', '.', VersionInputData::class), $entry['input']);
            } else {
                $this->assertArrayNotHasKey('input', $entry);
            }
        }

        $model = (new RouteManifestModelSource($this->app->make(ParticleRouteManifestSource::class)))->model()->toArray();
        $operations = array_column($model['operations'], null, 'name');
        $this->assertTrue($operations[$names[0]]['meta']['returnsBody']);
        $this->assertSame(str_replace('\\', '.', VersionInputData::class), $operations[$names[1]]['meta']['input']);
        $this->assertSame(str_replace('\\', '.', VersionInputData::class), $operations[$names[2]]['meta']['input']);
    }

    #[DataProvider('excludedRoutes')]
    public function test_undeclared_or_unpublished_controller_routes_stay_out(string $case, string $method): void
    {
        $route = Route::get('declared-test/'.$case, [DeclaredManifestController::class, $method]);
        if ($case !== 'unnamed') {
            $route->name('declared-test.'.$case);
        }
        if ($case !== 'missing-operation-id') {
            $route->beam()->operationId('declaredTest'.ucfirst($case));
        }
        if ($case === 'deprecated') {
            $route->beam()->visibility(RouteVisibility::Deprecated);
        }
        if ($case === 'bare-metadata-only') {
            $route->beam()->returns(DeclaredManifestResponse::class);
        }

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();
        $this->assertNotContains('declared-test/'.$case, array_column($manifest, 'path'));
    }

    public static function excludedRoutes(): array
    {
        return [
            'unnamed' => ['unnamed', 'show'],
            'deprecated' => ['deprecated', 'show'],
            'missing operation id' => ['missing-operation-id', 'show'],
            'missing trio' => ['missing-trio', 'plain'],
            'error shape only' => ['error-only', 'errorOnly'],
            'request without success shape' => ['request-only', 'requestOnly'],
            'bare return metadata is not the trio' => ['bare-metadata-only', 'plain'],
        ];
    }

    public function test_a_declared_stream_is_included_without_a_response_body(): void
    {
        Route::get('declared-test/stream', [DeclaredManifestController::class, 'stream'])->name('declared-test.stream')->beam()->operationId('declaredTestStream');
        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();
        $this->assertArrayHasKey('declared-test.stream', $manifest);
        $entry = $manifest['declared-test.stream'];
        $this->assertSame(['GET'], $entry['methods']);
        $this->assertSame(['tick' => [str_replace('\\', '.', DeclaredManifestResponse::class)]], $entry['streams']);
        $this->assertArrayNotHasKey('returns', $entry);
    }

    public function test_declared_request_types_join_the_type_emission_roots(): void
    {
        $this->app->register(BeamVersioningServiceProvider::class);
        Route::recordVersions('type-roots', \Illuminate\Database\Eloquent\Model::class);
        $declared = $this->app->make(DeclaredRouteTypes::class)->declared();
        $this->assertArrayHasKey(VersionInputData::class, $declared);
        $this->assertArrayHasKey(VersionListResponseData::class, $declared);
        $this->assertArrayHasKey(VersionResponseData::class, $declared);
    }

    public function test_a_resource_association_cannot_turn_a_declared_stream_into_a_row_response(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'stream-context', backing: \Illuminate\Database\Eloquent\Model::class, data: DeclaredManifestResponse::class,
        ));
        foreach (['resource', 'explicit-return'] as $binding) {
            $route = Route::get('declared-kind/'.$binding, [DeclaredManifestController::class, 'stream'])
                ->name('declared-kind.'.$binding)->beam()->operationId('declaredKind'.$binding)->inResource('stream-context');
            if ($binding === 'explicit-return') {
                $route->returns(DeclaredManifestResponse::class);
            }
        }

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();
        foreach (['resource', 'explicit-return'] as $binding) {
            $entry = $manifest['declared-kind.'.$binding];
            $this->assertArrayNotHasKey('returns', $entry);
            $this->assertSame(['tick' => [str_replace('\\', '.', DeclaredManifestResponse::class)]], $entry['streams']);
        }
    }
}

class DeclaredManifestController
{
    #[ResponseFromData(DeclaredManifestResponse::class)]
    public function show(): void {}

    public function plain(): void {}

    #[ResponseFromData(DeclaredManifestResponse::class, status: 422)]
    public function errorOnly(): void {}

    #[RequestFromData(DeclaredManifestRequest::class)]
    public function requestOnly(): void {}

    #[StreamsFromData('tick', DeclaredManifestResponse::class)]
    public function stream(): void {}
}

class DeclaredManifestResponse extends Data
{
    public function __construct(public bool $success) {}
}

class DeclaredManifestRequest extends Data
{
    public function __construct(public string $label) {}
}
