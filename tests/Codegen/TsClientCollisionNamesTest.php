<?php

namespace Splicewire\Beam\Tests\Codegen;

use LogicException;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

function collisionClient(array $tenant, array $operator = [], array $options = []): array
{
    $source = fn (array $routes): RouteManifestSource => new class($routes) implements RouteManifestSource
    {
        public function __construct(private array $routes) {}

        public function toArray(): array
        {
            return $this->routes;
        }
    };
    $model = (new RouteManifestModelSource($source($tenant), $source($operator)))->model()->toArray();

    return (new TsClientGenerator)->invoke(['model' => $model, 'options' => $options + ['emit_stores' => true]]);
}

function collisionRead(string $path = 'entries'): array
{
    return ['path' => $path, 'methods' => ['GET'], 'returns' => 'App.Data.Entry'];
}

function collisionStream(string $path = 'entries/events'): array
{
    return ['path' => $path, 'methods' => ['GET'], 'streams' => ['progress' => ['App.Data.Progress']]];
}

it('retains parameterized twin query identity without inventing parameterized stores', function () {
    $files = collisionClient([
        'api.v1.entries.body' => collisionRead('api/v1/entries/{entry}/body'),
        'entries.body' => collisionRead('entries/{entry}/body'),
    ])['files'];

    expect($files['hooks/entries.ts'])->toContain('useEntriesBody(', "queryKey: ['entries.body', params]", "route('entries.body', params)")
        ->and($files['hooks/api-v1-entries.ts'])->toContain('useApiV1EntriesBody(', "queryKey: ['api.v1.entries.body', params]", "route('api.v1.entries.body', params)")
        ->and($files)->not->toHaveKey('stores/entries.ts')
        ->and($files)->not->toHaveKey('stores/api-v1-entries.ts');
});

it('qualifies mutation hooks and their variables together without changing full routes or bodies', function () {
    $write = ['path' => 'entries/{entry}', 'methods' => ['PATCH'], 'returns' => 'App.Data.Entry', 'input' => 'App.Data.Edit'];
    $files = collisionClient(['api.v1.entries.update' => $write, 'entries.update' => $write])['files'];

    expect($files['hooks/entries.ts'])->toContain('export type EntriesUpdateVars = { params: { entry: string | number }; body: App.Data.Edit };',
        'export function useEntriesUpdate(', 'MutationOpts<App.Data.Entry, EntriesUpdateVars>', "api.patch(route('entries.update', vars.params), vars.body)")
        ->and($files['hooks/api-v1-entries.ts'])->toContain('export type ApiV1EntriesUpdateVars =', 'useApiV1EntriesUpdate(',
            'MutationOpts<App.Data.Entry, ApiV1EntriesUpdateVars>', "api.patch(route('api.v1.entries.update', vars.params), vars.body)");
});

it('retains void mutation variables when neither parameters nor body are sent', function () {
    $write = ['path' => 'entries', 'methods' => ['DELETE'], 'returns' => 'App.Data.Entry', 'input' => false];
    $files = collisionClient(['api.v1.entries.clear' => $write, 'entries.clear' => $write])['files'];

    expect($files['hooks/api-v1-entries.ts'])->toContain('useApiV1EntriesClear(', 'MutationOpts<App.Data.Entry, void>', "api.delete(route('api.v1.entries.clear'))")
        ->not->toContain('ClearVars', 'vars.body');
});

it('qualifies twin stream functions and event types while preserving event payloads and route parameters', function () {
    $files = collisionClient([
        'api.v1.entries.events' => collisionStream('api/v1/entries/{entry}/events'),
        'entries.events' => collisionStream('entries/{entry}/events'),
    ])['files'];

    expect($files['hooks/entries.ts'])->toContain('export type EntriesEventsStreamEvent =', 'export function useEntriesEventsStream(',
        "event: 'progress'; data: App.Data.Progress", "route('entries.events', params)")
        ->and($files['hooks/api-v1-entries.ts'])->toContain('export type ApiV1EntriesEventsStreamEvent =', 'export function useApiV1EntriesEventsStream(',
            'useSseStream<ApiV1EntriesEventsStreamEvent>', "route('api.v1.entries.events', params)");
});

it('uses one domain decision for a route exposing both typed and streamed results', function () {
    $entry = collisionRead() + ['streams' => ['progress' => ['App.Data.Progress']]];
    $files = collisionClient(['api.v1.entries.watch' => $entry, 'entries.watch' => $entry])['files'];

    expect($files['hooks/api-v1-entries.ts'])->toContain('useApiV1EntriesWatch(', 'useApiV1EntriesWatchStream(', 'ApiV1EntriesWatchStreamEvent')
        ->and(substr_count($files['hooks/api-v1-entries.ts'], "import { api }"))->toBe(1)
        ->and($files['stores/api-v1-entries.ts'])->toContain('ApiV1EntriesWatchState', 'useApiV1EntriesWatchStore');
});

it('detects a transport collision between a typed hook and a stream hook', function () {
    $files = collisionClient(['api.v1.entries.watchStream' => collisionRead(), 'entries.watch' => collisionStream()])['files'];

    expect($files['hooks/api-v1-entries.ts'])->toContain('useApiV1EntriesWatchStream(')
        ->and($files['hooks/entries.ts'])->toContain('useEntriesWatchStream(');
});

it('detects a transport collision between a query hook and a store export', function () {
    $files = collisionClient(['api.v1.entries.bodyStore' => collisionRead(), 'entries.body' => collisionRead()])['files'];

    expect($files['hooks/api-v1-entries.ts'])->toContain('useApiV1EntriesBodyStore(')
        ->and($files['stores/entries.ts'])->toContain('export const useEntriesBodyStore =');
});

it('leaves noncolliding API-only and mixed transport names short', function () {
    $api = collisionClient(['api.v1.entries.index' => collisionRead()])['files'];
    $mixed = collisionClient(['api.v1.entries.index' => collisionRead(), 'entries.show' => collisionRead('entries/{entry}')])['files'];

    expect($api['hooks/entries.ts'])->toContain('useEntriesIndex(', "queryKey: ['api.v1.entries.index']")
        ->and($api['stores/entries.ts'])->toContain('EntriesIndexState', 'useEntriesIndexStore')
        ->and($mixed['hooks/entries.ts'])->toContain('useEntriesIndex(', 'useEntriesShow(')
        ->and($mixed)->not->toHaveKey('hooks/api-v1-entries.ts');
});

it('retains existing cross-realm qualification and client bindings for typed endpoints', function () {
    $files = collisionClient(['entries.index' => collisionRead()], ['api.operator.entries.index' => collisionRead()])['files'];

    expect($files['hooks/entries.ts'])->toContain('useEntriesIndex(', "import { api }", "route('entries.index')")
        ->and($files['hooks/operator-entries.ts'])->toContain('useOperatorEntriesIndex(', "import { operatorApi }", "operatorRoute('api.operator.entries.index')")
        ->and($files['stores/operator-entries.ts'])->toContain('OperatorEntriesIndexState', 'useOperatorEntriesIndexStore');
});

it('also keeps cross-realm streams out of the tenant client file', function () {
    $files = collisionClient(['entries.index' => collisionRead()], ['api.operator.entries.events' => collisionStream()])['files'];

    expect($files['hooks/operator-entries.ts'])->toContain('OperatorEntriesEventsStreamEvent', 'useOperatorEntriesEventsStream(',
        "useSseStream<OperatorEntriesEventsStreamEvent>(operatorApi, operatorRoute('api.operator.entries.events')")
        ->and($files['hooks/entries.ts'])->not->toContain('operatorApi', 'EventsStream');
});

it('retains API-only stream names without an unnecessary transport or realm prefix', function () {
    $files = collisionClient([], ['api.operator.entries.events' => collisionStream()])['files'];

    expect($files['hooks/entries.ts'])->toContain('useEntriesEventsStream(', 'EntriesEventsStreamEvent', 'operatorApi')
        ->and($files)->not->toHaveKey('hooks/operator-entries.ts');
});

it('qualifies only the API side independently of manifest order', function (bool $reverse) {
    $routes = ['api.v1.entries.index' => collisionRead(), 'entries.index' => collisionRead()];
    $files = collisionClient($reverse ? array_reverse($routes, true) : $routes)['files'];

    expect($files['hooks/entries.ts'])->toContain('export function useEntriesIndex(')->not->toContain('useApiV1')
        ->and($files['hooks/api-v1-entries.ts'])->toContain('export function useApiV1EntriesIndex(');
})->with([false, true]);

it('refuses a secondary transport-qualified file collision and names both routes', function () {
    expect(fn () => collisionClient([
        'api.v1.entries.index' => collisionRead(), 'entries.index' => collisionRead(), 'api-v1-entries.other' => collisionRead(),
    ]))->toThrow(LogicException::class, 'Client file [hooks/api-v1-entries.ts] collides between routes [api.v1.entries.index] and [api-v1-entries.other].');
});

it('refuses a realm-qualified file collision even when the action names differ', function () {
    expect(fn () => collisionClient(['entries.index' => collisionRead(), 'operator-entries.other' => collisionRead()],
        ['api.operator.entries.index' => collisionRead()]))->toThrow(LogicException::class,
        'Client file [hooks/operator-entries.ts] collides between routes [operator-entries.other] and [api.operator.entries.index].');
});

it('refuses duplicate derived exports across different files instead of overwriting an identifier', function () {
    expect(fn () => collisionClient(['foo-bar.index' => collisionRead(), 'foo_bar.index' => collisionRead()]))
        ->toThrow(LogicException::class, 'Client identifier [useFooBarIndex] collides between routes [foo-bar.index] and [foo_bar.index].');
});

it('refuses unresolved same-family hook versus stream collisions with both route names', function () {
    expect(fn () => collisionClient(['entries.watchStream' => collisionRead(), 'entries.watch' => collisionStream()]))
        ->toThrow(LogicException::class, 'Client identifier [useEntriesWatchStream] collides between routes [entries.watchStream] and [entries.watch].');
});

it('reserves newly qualified exports against existing camel-case domains in other files', function () {
    expect(fn () => collisionClient([
        'api.v1.entries.index' => collisionRead(), 'entries.index' => collisionRead(), 'apiV1Entries.index' => collisionRead(),
    ]))->toThrow(LogicException::class, 'Client identifier [useApiV1EntriesIndex] collides between routes [api.v1.entries.index] and [apiV1Entries.index].');
});

it('reserves only stores that the selected output actually emits', function () {
    $files = collisionClient(['api.v1.entries.bodyStore' => collisionRead(), 'entries.body' => collisionRead()], [], ['emit_stores' => false])['files'];

    expect($files['hooks/entries.ts'])->toContain('useEntriesBodyStore(', 'useEntriesBody(')
        ->and($files)->not->toHaveKey('hooks/api-v1-entries.ts')
        ->and($files)->not->toHaveKey('stores/entries.ts');
});

it('retains untyped full route families without renaming the typed client', function () {
    $files = collisionClient(['api.v1.entries.index' => ['path' => 'api/v1/entries', 'methods' => ['GET']], 'entries.index' => collisionRead()])['files'];

    expect($files['routes.ts'])->toContain("'api.v1.entries.index'", "'entries.index'")
        ->and($files['hooks/entries.ts'])->toContain('useEntriesIndex(')
        ->and($files)->not->toHaveKey('hooks/api-v1-entries.ts');
});

it('applies the same collision-only transport distinction inside an operator map', function () {
    $files = collisionClient([], ['api.operator.entries.index' => collisionRead(), 'entries.index' => collisionRead()])['files'];

    expect($files['hooks/entries.ts'])->toContain('useEntriesIndex(', "operatorRoute('entries.index')")
        ->and($files['hooks/api-operator-entries.ts'])->toContain('useApiOperatorEntriesIndex(', "operatorRoute('api.operator.entries.index')");
});
