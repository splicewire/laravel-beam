<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Support\Facades\Route;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Scribe\OpenApi\OperationIdGenerator;
use Splicewire\Beam\Tests\TestCase;

/**
 * The generate path of {@see OperationIdGenerator} — the half `OperationIdGeneratorKeyingTest` leaves out
 * (api-surface-coherence 36/78). Each case registers a real route and hands the generator the
 * `OutputEndpointData` Scribe would build for it, then reads the `operationId` off the assembled operation.
 *
 * The generator is resolved the way Scribe resolves it — `app()->makeWith(..., ['config' => ...])` — so
 * that the constructor's autowired `RouteMetadataReader` is the one the provider bound, not a bare `new`.
 */
class OperationIdGeneratorTest extends TestCase
{
    protected function generator(): OperationIdGenerator
    {
        return $this->app->makeWith(OperationIdGenerator::class, ['config' => new DocumentationConfig([])]);
    }

    protected function endpoint(string $verb, string $uri): OutputEndpointData
    {
        return new OutputEndpointData(['httpMethods' => [$verb], 'uri' => $uri]);
    }

    protected function operationId(string $verb, string $uri, array $pathItem = []): string
    {
        $pathItem = $this->generator()->pathItem($pathItem, [], $this->endpoint($verb, $uri));

        return $pathItem['operationId'];
    }

    /** Rung (E): the mount wrote one, the generator reads it back through the typed namespace. */
    public function test_a_declared_operation_id_wins(): void
    {
        Route::get('api/v1/widgets/{id}/versions', fn () => [])->beam()->operationId('widgetsVersionsIndex');

        $this->assertSame('widgetsVersionsIndex', $this->operationId('GET', 'api/v1/widgets/{id}/versions'));
    }

    /** Rung (B): an undeclared route gets the wire shape — ugly, unique, never the title. */
    public function test_an_undeclared_route_falls_to_the_wire_shape(): void
    {
        Route::get('api/v1/widgets/{id}/versions', fn () => []);

        $this->assertSame('getApiV1WidgetsIdVersions', $this->operationId('GET', 'api/v1/widgets/{id}/versions'));
    }

    /**
     * The precedence is TOTAL: whatever `BaseGenerator` derived from the title is overwritten, not
     * deferred to. This is what supersedes the vendor `[^\w+]` bug rather than patching it.
     */
    public function test_it_overwrites_the_title_derived_id_base_generator_wrote(): void
    {
        Route::post('api/v1/reset-password', fn () => []);

        $id = $this->operationId('POST', 'api/v1/reset-password', ['operationId' => 'confirmAResetWithToken+NewPassword']);

        $this->assertSame('postApiV1ResetPassword', $id);
    }

    /** The documented normalisation: the router keeps `{param?}`, the document writes `{param}`. */
    public function test_a_declaration_on_an_optional_parameter_route_is_still_found(): void
    {
        Route::get('api/v1/widgets/{id?}', fn () => [])->beam()->operationId('widgetsShow');

        $this->assertSame('widgetsShow', $this->operationId('GET', 'api/v1/widgets/{id}'));
    }

    /**
     * A double registration is ambiguous, so the generator declares nothing for it and falls to (B). The
     * host's totality guard is what turns the double registration itself into a red test.
     *
     * ⚠️ Registered on two DOMAINS, because that is the only double registration `Router::getRoutes()` can
     * show anyone: `RouteCollection` keys `allRoutes` by `method.domain.uri`, so a same-domain duplicate is
     * LAST-WINS and invisible to every reader of the collection, this generator included. Measured while
     * writing this test — the first version registered the same uri twice with no domain and read the
     * second declaration back as if it were alone.
     */
    public function test_a_doubly_registered_wire_key_declares_nothing(): void
    {
        Route::domain('a.test')->get('api/v1/search', fn () => [])->beam()->operationId('searchOne');
        Route::domain('b.test')->get('api/v1/search', fn () => [])->beam()->operationId('searchTwo');

        $this->assertSame('getApiV1Search', $this->operationId('GET', 'api/v1/search'));
    }

    /** One controller method on two verbs of one uri is two operations, each keyed by its own verb. */
    public function test_two_verbs_on_one_uri_read_their_own_declarations(): void
    {
        Route::match(['get', 'post'], 'api/v1/widgets', fn () => [])->beam()->operationId('widgetsBoth');

        $this->assertSame('widgetsBoth', $this->operationId('GET', 'api/v1/widgets'));
        $this->assertSame('widgetsBoth', $this->operationId('POST', 'api/v1/widgets'));
        $this->assertSame('putApiV1Widgets', $this->operationId('PUT', 'api/v1/widgets'));
    }
}
