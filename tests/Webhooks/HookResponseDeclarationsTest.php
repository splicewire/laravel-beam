<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use ReflectionMethod;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\Http\HookDeliveriesController;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;
use Splicewire\Beam\Webhooks\Http\HookSubscriptionController;

uses(TestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub')->up();
    $this->actingAs(new User);
    Gate::before(fn () => true);
    Bus::fake();
    Http::preventStrayRequests();
    app(EventTypeRegistry::class)->register(new EventType(name: 'declarations.ready', subjectless: true));
    Route::get('declaration-hooks/events', [HookEventCatalogController::class, 'index']);
    Route::post('declaration-hooks', [HookSubscriptionController::class, 'store']);
    Route::get('declaration-hooks/{hook}/deliveries', [HookDeliveriesController::class, 'index']);
});

function declaredHookBody(TestResponse $response, string $controller, string $method): array
{
    $declaration = (new ReflectionMethod($controller, $method))->getAttributes(ResponseFromData::class)[0]->newInstance();
    $body = $response->json();

    expect($response->status())->toBe($declaration->status)
        ->and($body)->toHaveKeys(['data', 'success', 'message', 'limit', 'offset', 'total'])
        ->and($declaration->dataClass::from($body)->toArray())->toEqual($body);

    $schema = app(Generator::class)->forResponse()->generate(new ReflectionClass($declaration->dataClass));
    expect($schema['properties'])->toHaveKeys(['data', 'success', 'message', 'limit', 'offset', 'total']);

    return $schema;
}

it('declares the complete catalog response emitted by the controller', function () {
    $response = $this->getJson('/declaration-hooks/events')->assertOk();
    declaredHookBody($response, HookEventCatalogController::class, 'index');
    expect($response->json('data.events'))->not->toBeEmpty();
});

it('declares empty and populated delivery lists with their response metadata', function (bool $populated) {
    $controller = new class($populated) extends HookDeliveriesController
    {
        public function __construct(private bool $populated) {}

        protected function rows(Hook $hook, int $limit): array
        {
            return $this->populated ? [(object) [
                'request_id' => 'delivery-1', 'path' => '/inbox', 'method' => 'POST',
                'response_status' => 202, 'is_error' => false, 'request_at' => null, 'response_at' => null,
            ]] : [];
        }
    };
    app()->instance(HookDeliveriesController::class, $controller);
    $hook = Hook::create(['endpoint' => 'https://receiver.test/inbox', 'secret' => Hook::mintSecret(), 'events' => ['declarations.ready']]);
    $response = $this->getJson("/declaration-hooks/{$hook->id}/deliveries?limit=7")->assertOk();
    $schema = declaredHookBody($response, HookDeliveriesController::class, 'index');

    expect($response->json('data'))->toHaveCount($populated ? 1 : 0)
        ->and($response->json('limit'))->toBe(7)
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items'])->toHaveKey('$ref');
})->with([false, true]);

it('declares the 201 reveal-once subscription response without changing its body', function () {
    $response = $this->postJson('/declaration-hooks', [
        'endpoint' => 'https://receiver.test/inbox', 'events' => ['declarations.ready'],
    ])->assertCreated();
    declaredHookBody($response, HookSubscriptionController::class, 'store');

    expect($response->json('data.secret'))->not->toBeEmpty()
        ->and($response->json('data.hook.id'))->not->toBeEmpty()
        ->and($response->json('data.pinged'))->toBeBool();
});
