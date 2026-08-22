<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * particle-doctrine-convergence ticket 02 — an operation's business logic returns a PAYLOAD, and response
 * construction is a separate concern, so the same logic can serve more than one transport.
 *
 * The escape hatch (a handler returning a response object directly) is a supported choice rather than a
 * violation, and it is load-bearing: three live operations each need it for a distinct reason — a binary
 * download, a redirect carrying session mutation, and a specific accepted status code.
 */
class OperationPayloadRespondTest extends TestCase
{
    public function test_a_declared_payload_data_object_is_enveloped_without_the_handler_building_a_response(): void
    {
        $op = $this->op(handle: fn () => new OpPayloadData('w-1', 42));

        $response = $this->finish($op);

        $this->assertSame(['data' => ['id' => 'w-1', 'total' => 42]], $this->decode($response));
    }

    public function test_an_array_payload_is_returned_as_is_so_existing_handlers_are_not_double_wrapped(): void
    {
        // Every pre-existing read/write handler already returned its own `{ data: … }` envelope. Enveloping
        // again would corrupt all of them, so this is the compatibility guarantee.
        $op = $this->op(handle: fn () => ['data' => ['id' => 'w-1']]);

        $this->assertSame(['data' => ['id' => 'w-1']], $this->finishRaw($op));
    }

    public function test_respond_is_consulted_for_a_read_kind_not_only_a_task(): void
    {
        $op = $this->op(
            kind: OperationKind::Read,
            handle: fn () => new OpPayloadData('w-1', 1),
            respond: fn (OpPayloadData $payload) => new OpPayloadData($payload->id, 999),
        );

        $this->assertSame(['data' => ['id' => 'w-1', 'total' => 999]], $this->decode($this->finish($op)));
    }

    public function test_respond_receives_the_handlers_payload(): void
    {
        $seen = null;
        $op = $this->op(
            handle: fn () => new OpPayloadData('w-1', 7),
            respond: function ($payload) use (&$seen) {
                $seen = $payload;

                return $payload;
            },
        );

        $this->finish($op);

        $this->assertInstanceOf(OpPayloadData::class, $seen);
        $this->assertSame(7, $seen->total);
    }

    public function test_respond_receives_the_model_as_its_second_argument(): void
    {
        $model = (object) ['id' => 'm-1'];
        $seen = null;
        $op = $this->op(
            handle: fn () => new OpPayloadData('w-1', 1),
            respond: function ($payload, $given) use (&$seen) {
                $seen = $given;

                return $payload;
            },
        );

        $this->finish($op, $model);

        $this->assertSame($model, $seen);
    }

    public function test_a_respond_projector_may_return_a_full_response(): void
    {
        $op = $this->op(
            handle: fn () => new OpPayloadData('w-1', 1),
            respond: fn () => new JsonResponse(['custom' => true], 418),
        );

        $response = $this->finishRaw($op);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(418, $response->getStatusCode());
    }

    // ── The escape hatch, one test per live reason ───────────────────────────────────────────────────

    public function test_a_streamed_binary_response_passes_through_unflattened(): void
    {
        // The binary download's pass-through is a stated contract; wrapping it in a JSON envelope would
        // corrupt the download outright.
        $binary = new StreamedResponse(fn () => print ('bytes'), 200, ['Content-Type' => 'audio/mpeg']);
        $op = $this->op(handle: fn () => $binary);

        $this->assertSame($binary, $this->finishRaw($op));
    }

    public function test_a_redirect_response_passes_through_so_session_mutation_survives(): void
    {
        $redirect = new RedirectResponse('/dashboard');
        $op = $this->op(handle: fn () => $redirect);

        $this->assertSame($redirect, $this->finishRaw($op));
    }

    public function test_an_operation_carrying_an_accepted_status_returns_that_exact_status(): void
    {
        // A declared payload slot has no status channel, which is exactly why the escape hatch has to stay.
        $accepted = new JsonResponse(['data' => null], Response::HTTP_ACCEPTED);
        $op = $this->op(kind: OperationKind::Read, handle: fn () => $accepted);

        $response = $this->finishRaw($op);

        $this->assertSame(202, $response->getStatusCode());
    }

    // ── Declared input validation ───────────────────────────────────────────────────────────────────

    public function test_a_declared_input_class_is_validated_before_the_handler_runs(): void
    {
        $ran = false;
        $op = $this->op(
            handle: function () use (&$ran) {
                $ran = true;

                return null;
            },
            input: OpInputData::class,
        );

        try {
            $this->controller()->callValidateInput($op, Request::create('/', 'POST', []));
            $this->fail('Expected the declared input contract to reject an empty payload.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($ran, 'The handler must not run when the declared input fails validation.');
    }

    public function test_a_valid_payload_passes_the_declared_input_contract(): void
    {
        $op = $this->op(handle: fn () => null, input: OpInputData::class);

        $this->controller()->callValidateInput($op, Request::create('/', 'POST', ['label' => 'ok']));

        $this->addToAssertionCount(1);
    }

    public function test_an_operation_declaring_no_input_is_not_validated(): void
    {
        $op = $this->op(handle: fn () => null);

        $this->controller()->callValidateInput($op, Request::create('/', 'POST', []));

        $this->addToAssertionCount(1);
    }

    // ── Deliberately empty input (api-surface-coherence 30) ─────────────────────────────────────────

    public function test_an_operation_declaring_no_input_accepts_a_body_because_null_means_undeclared(): void
    {
        // The residue state, pinned rather than endorsed: `null` is where every operation starts, so it must
        // stay permissive until the declaration sweep closes it. This assertion is what the eventual flip to
        // "null means reject" has to come back and delete on purpose.
        $op = $this->op(handle: fn () => null);

        $this->controller()->callValidateInput($op, Request::create('/', 'POST', ['anything' => 'goes']));

        $this->addToAssertionCount(1);
    }

    public function test_an_operation_declaring_false_rejects_a_body(): void
    {
        $op = $this->op(handle: fn () => null, input: false);

        try {
            $this->controller()->callValidateInput($op, Request::create('/', 'POST', ['label' => 'nope']));
            $this->fail('Expected an op declared to accept nothing to reject a payload.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('label', $e->errors());
        }
    }

    public function test_an_operation_declaring_false_accepts_an_empty_body(): void
    {
        $op = $this->op(handle: fn () => null, input: false);

        $this->controller()->callValidateInput($op, Request::create('/', 'POST', []));

        $this->addToAssertionCount(1);
    }

    public function test_a_task_declaring_false_still_accepts_the_frameworks_own_async_flag(): void
    {
        // `?async` is beam's parameter, not the caller's payload, so an op that accepts nothing of its own
        // must not reject it — otherwise declaring `input: false` would silently break the one convention
        // every Task depends on.
        $op = $this->op(handle: fn () => null, kind: OperationKind::Task, input: false);

        $this->controller()->callValidateInput($op, Request::create('/?async=false', 'POST'));

        $this->addToAssertionCount(1);
    }

    public function test_a_get_operation_declaring_false_rejects_query_input_not_body_input(): void
    {
        // The mount picks the method, so a GET op's payload axis is the query string. Reading the body here
        // would examine an axis the endpoint does not accept input on in the first place.
        $op = $this->op(handle: fn () => null, input: false);

        try {
            $this->controller()->callValidateInput($op, Request::create('/?unchunk=1', 'GET'));
            $this->fail('Expected a GET op declared to accept nothing to reject a query parameter.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unchunk', $e->errors());
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function op(
        ?callable $handle = null,
        OperationKind $kind = OperationKind::Write,
        ?callable $respond = null,
        string|false|null $input = null,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: 'widgets',
            name: 'recalculate',
            kind: $kind,
            model: 'stdClass',
            handle: $handle === null ? fn () => null : \Closure::fromCallable($handle),
            respond: $respond === null ? null : \Closure::fromCallable($respond),
            input: $input,
        );
    }

    private function controller(): ExposedPayloadController
    {
        return $this->app->make(ExposedPayloadController::class);
    }

    /** Run the handler through the finish seam, as the controller's kind branch does. */
    private function finishRaw(ParticleOperation $operation, mixed $model = null): mixed
    {
        $model ??= (object) [];

        return $this->controller()->callFinish(
            $operation,
            ($operation->handle)($model, Request::create('/'), null),
            $model,
        );
    }

    private function finish(ParticleOperation $operation, mixed $model = null): mixed
    {
        return $this->finishRaw($operation, $model);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $responsable): array
    {
        return json_decode($responsable->toResponse(Request::create('/'))->getContent(), true);
    }
}

class OpPayloadData extends Data
{
    public function __construct(
        public string $id,
        public int $total,
    ) {}
}

class OpInputData extends Data
{
    public function __construct(
        public string $label,
    ) {}
}

/** Exposes the protected payload/respond seam so it is testable without a routed request + DB model. */
class ExposedPayloadController extends ParticleOperationController
{
    public function callFinish(ParticleOperation $operation, mixed $payload, mixed $model): mixed
    {
        return $this->finish($operation, $payload, $model);
    }

    public function callValidateInput(ParticleOperation $operation, Request $request): void
    {
        $this->validateInput($operation, $request);
    }
}
