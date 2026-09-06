<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

class ClientBodyPayload extends Data
{
    public function __construct(public string $id) {}
}

class ClientBodyEnvelope extends Data
{
    public function __construct(public ClientBodyPayload $data, public bool $success = true) {}
}

class ClientBodyController
{
    #[ResponseFromData(ClientBodyPayload::class, status: 422)]
    #[ResponseFromData(ClientBodyEnvelope::class, status: 201)]
    public function envelope() {}

    #[ResponseFromData(ClientBodyPayload::class)]
    public function bare() {}

    #[StreamsFromData('progress', [ClientBodyPayload::class, ClientBodyEnvelope::class])]
    public function stream() {}
}

it('exposes raw attribute classes with shared success selection and reflection caching', function () {
    $resolver = app(RouteReturnType::class);
    $route = new Route(['GET'], 'examples', ['controller' => ClientBodyController::class.'@envelope']);

    expect($resolver->fromResponseAttribute($route))->toBe(ClientBodyEnvelope::class)
        ->and($resolver->for($route)['returnsBody'])->toBeTrue()
        ->and($resolver->reflectionCount())->toBe(1);

    $stream = new Route(['GET'], 'events', ['controller' => ClientBodyController::class.'@stream']);
    expect($resolver->fromStreamsAttribute($stream))->toBe([
        'progress' => [ClientBodyPayload::class, ClientBodyEnvelope::class],
    ])->and($resolver->fromResponseAttribute($stream))->toBeNull()
        ->and($resolver->reflectionCount())->toBe(2);
});

it('preserves the declared response projection through the model into hooks and stores', function (string $kind, bool $body, string $type, bool $many) {
    $route = new Route(['GET'], 'examples', [
        'uses' => ClientBodyController::class.'@'.($kind === 'envelope' ? 'envelope' : 'bare'),
        'controller' => ClientBodyController::class.'@'.($kind === 'envelope' ? 'envelope' : 'bare'),
    ]);

    if (str_starts_with($kind, 'particle')) {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'client-body-examples', backing: Model::class, data: ClientBodyPayload::class, filterable: false,
        ));
        $route->setAction(['uses' => ParticleController::class.'@index', 'controller' => ParticleController::class.'@index']);
        $route->defaults(ParticleController::RESOURCE, 'client-body-examples');
    } elseif ($kind === 'operation') {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'client-body-examples', name: 'read', kind: OperationKind::Read,
            handle: fn () => null, output: ClientBodyPayload::class,
        ));
        $route->setAction(['uses' => ParticleOperationController::class.'@invoke', 'controller' => ParticleOperationController::class.'@invoke']);
        $route->defaults(ParticleOperationController::RESOURCE, 'client-body-examples');
        $route->defaults(ParticleOperationController::NAME, 'read');
    } elseif ($kind === 'explicit') {
        (new BeamRouteProxy($route))->returns(ClientBodyPayload::class);
    }
    $route->name($many ? 'examples.index' : 'examples.show');

    $resolved = app(RouteReturnType::class)->for($route);
    expect($resolved['returnsBody'] ?? false)->toBe($body)
        ->and($resolved['many'])->toBe($many);

    $entry = ['path' => 'examples', 'methods' => ['GET'], 'returns' => $resolved['type']];
    if (isset($resolved['returnsBody'])) {
        $entry['returnsBody'] = $resolved['returnsBody'];
    }
    if ($resolved['many']) {
        $entry['returnsMany'] = true;
    }

    $source = new class($entry) implements RouteManifestSource
    {
        public function __construct(private array $entry) {}

        public function toArray(): array
        {
            return ['examples.index' => $this->entry, 'examples.store' => ['methods' => ['POST']] + $this->entry];
        }
    };
    $model = (new RouteManifestModelSource($source))->model()->toArray();
    $files = (new TsClientGenerator)->invoke(['model' => $model, 'options' => ['emit_stores' => true]])['files'];
    $expression = $body ? 'res.data' : 'res.data.data';
    $qualified = str_replace('\\', '.', $type).($resolved['many'] ? '[]' : '');

    expect(substr_count($files['hooks/examples.ts'], "return {$expression} as {$qualified};"))->toBe(2)
        ->and($files['stores/examples.ts'])->toContain("set({ data: {$expression} as {$qualified}, loading: false });");
})->with([
    'full envelope' => ['envelope', true, ClientBodyEnvelope::class, false],
    'bare body' => ['bare', true, ClientBodyPayload::class, false],
    'particle payload collection' => ['particle-many', false, ClientBodyPayload::class, true],
    'particle payload' => ['particle', false, ClientBodyPayload::class, false],
    'operation payload' => ['operation', false, ClientBodyPayload::class, false],
    'explicit payload overrides body attribute' => ['explicit', false, ClientBodyPayload::class, false],
]);
