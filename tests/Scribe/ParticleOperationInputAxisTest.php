<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationParameterStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleRequestStrategy;
use Splicewire\Beam\Tests\TestCase;

class OpQueryFixtureData extends Data
{
    public function __construct(
        public string $scope,
        public ?bool $unchunk = null,
    ) {}
}

/**
 * api-surface-coherence ticket 30 — an operation declares WHAT it accepts; the MOUNT decides where that
 * arrives.
 *
 * `Route::particleOp()` chooses the HTTP method, so one `input:` class publishes as a request body on a write
 * op and as query parameters on a GET one. Neither the declarant nor the registry knows which — only the
 * route does — which is why the two strategies are tested together here rather than each against its own
 * half: the property under test is that exactly ONE of them claims any given operation.
 *
 * The second subject is `?async`. It is beam's parameter rather than the host's — no `input:` a host writes
 * could declare it — and before this it reached the reference only as prose in the endpoint description,
 * describing a real, enforced parameter that a generated client had no way to send.
 */
class ParticleOperationInputAxisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The strategies resolve the registry out of the container; without the singleton binding a
        // `register()` here and the strategies' read would land on two different instances.
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    // ── The declared axis ───────────────────────────────────────────────────────────────────────────

    public function test_a_write_operations_declared_input_documents_as_a_request_body(): void
    {
        $this->registerOp(input: OpQueryFixtureData::class);
        $endpoint = $this->endpoint(['POST']);

        $body = $this->bodyStrategy()($endpoint);

        $this->assertArrayHasKey('scope', $body);
        $this->assertArrayHasKey('unchunk', $body);
        $this->assertArrayHasKey('scope', $endpoint->custom['dataRequestSchema']['properties']);
    }

    public function test_a_write_operations_declared_input_does_not_also_document_as_query_parameters(): void
    {
        $this->registerOp(input: OpQueryFixtureData::class);

        $this->assertSame([], $this->queryStrategy()($this->endpoint(['POST'])));
    }

    public function test_a_get_operations_declared_input_documents_as_query_parameters(): void
    {
        $this->registerOp(input: OpQueryFixtureData::class, kind: OperationKind::Read);
        $endpoint = $this->endpoint(['GET']);

        $query = $this->queryStrategy()($endpoint);

        $this->assertArrayHasKey('scope', $query);
        $this->assertArrayHasKey('unchunk', $query);
    }

    public function test_a_get_operations_declared_input_does_not_also_document_as_a_request_body(): void
    {
        // The failure this pins is the one a reader would never notice: a GET publishing a requestBody is
        // valid-looking YAML that no client can send (the shape ticket 67 catalogues on the hand-rolled
        // routes). Documenting on both axes would be worse than documenting on neither.
        $this->registerOp(input: OpQueryFixtureData::class, kind: OperationKind::Read);

        $this->assertSame([], $this->bodyStrategy()($this->endpoint(['GET'])));
    }

    public function test_an_undeclared_input_documents_nothing_on_either_axis(): void
    {
        $this->registerOp(input: null);

        $this->assertSame([], $this->bodyStrategy()($this->endpoint(['POST'])));
        $this->assertSame([], $this->queryStrategy()($this->endpoint(['POST'])));
    }

    public function test_a_deliberately_empty_input_documents_nothing_on_either_axis(): void
    {
        // `false` and `null` are identical in the ARTIFACT and different in MEANING: the distinction is
        // enforced at the controller and audited by the sweep, not published. Pinned so a later change that
        // starts emitting something for `false` has to say so.
        $this->registerOp(input: false);

        $this->assertSame([], $this->bodyStrategy()($this->endpoint(['POST'])));
        $this->assertSame([], $this->queryStrategy()($this->endpoint(['POST'])));
    }

    // ── The framework's own axis ────────────────────────────────────────────────────────────────────

    public function test_a_task_publishes_the_async_flag_it_actually_honours(): void
    {
        $this->registerOp(kind: OperationKind::Task);

        $query = $this->queryStrategy()($this->endpoint(['POST']));

        $this->assertArrayHasKey(ParticleOperationController::ASYNC, $query);
        $this->assertSame('boolean', $query[ParticleOperationController::ASYNC]['type']);
        $this->assertFalse($query[ParticleOperationController::ASYNC]['required']);
    }

    #[DataProvider('unqueuedKinds')]
    public function test_a_kind_that_is_never_queued_publishes_no_async_flag(OperationKind $kind): void
    {
        $this->registerOp(kind: $kind, output: $kind === OperationKind::Stream ? ['tick' => []] : null);

        $this->assertSame([], $this->queryStrategy()($this->endpoint(['POST'])));
    }

    public static function unqueuedKinds(): array
    {
        return [
            'read' => [OperationKind::Read],
            'write' => [OperationKind::Write],
            'stream' => [OperationKind::Stream],
        ];
    }

    public function test_both_strategies_defer_on_a_route_that_is_not_an_operation(): void
    {
        $route = new Route(['GET'], 'catalogs', [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]);

        $endpoint = ExtractedEndpointData::fromRoute($route);

        $this->assertNull($this->queryStrategy()($endpoint));
        $this->assertNull($this->bodyStrategy()($endpoint));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function registerOp(
        string|false|null $input = null,
        OperationKind $kind = OperationKind::Write,
        string|array|null $output = null,
    ): void {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'catalogs',
            name: 'recalculate',
            kind: $kind,
            model: 'stdClass',
            handle: fn () => null,
            input: $input,
            output: $output,
        ));
    }

    /** @param  list<string>  $methods */
    private function endpoint(array $methods): ExtractedEndpointData
    {
        $route = (new Route($methods, 'catalogs/{id}/op/recalculate', [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, 'catalogs')
            ->defaults(ParticleOperationController::NAME, 'recalculate');

        return ExtractedEndpointData::fromRoute($route);
    }

    private function queryStrategy(): ParticleOperationParameterStrategy
    {
        return new ParticleOperationParameterStrategy(new DocumentationConfig([]));
    }

    private function bodyStrategy(): ParticleRequestStrategy
    {
        return new ParticleRequestStrategy(new DocumentationConfig([]));
    }
}
