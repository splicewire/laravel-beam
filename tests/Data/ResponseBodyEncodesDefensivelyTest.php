<?php

namespace Splicewire\Beam\Tests\Data;

use RuntimeException;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Tests\TestCase;

/**
 * The error envelope must never be able to replace the error it was built to report.
 *
 * Measured at the flagship (api-surface-coherence 109): `GET /api/v1/hooks` answered
 * `500 Non-backed enums have no default serialization` from `JsonResponse.php:91`, while the
 * real failure was `SQLSTATE[42P01]: relation "beam_hooks" does not exist`. The debug payload
 * carried the original exception's stack trace **with its arguments**, one of which was a pure
 * enum — `json_encode` refused it, and the envelope's own failure became the response.
 *
 * Non-backed enums are one instance, not the class. Closures, resources, recursive structures
 * and objects with a throwing `jsonSerialize()` break `json_encode` the same way, so these
 * cases pin the general property: whatever is in the payload, the ORIGINAL message survives.
 */
class ResponseBodyEncodesDefensivelyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.debug', true);
    }

    private function envelopeCarrying(mixed $traceArg): array
    {
        $body = ResponseBody::from([
            'message' => 'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            'debug' => [
                'exception' => RuntimeException::class,
                'trace' => [['function' => 'handle', 'args' => [$traceArg]]],
            ],
        ]);
        $body->success = false;
        $body->statusCode = ResponseBody::HTTP_SERVER_ERROR;

        $response = $body->toResponse(null);

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    }

    public function test_a_non_backed_enum_in_a_trace_argument_does_not_replace_the_error(): void
    {
        [$status, $payload] = $this->envelopeCarrying(NonBackedTraceEnum::Alpha);

        $this->assertSame(500, $status);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
        $this->assertSame(RuntimeException::class, $payload['debug']['exception'] ?? null);
    }

    public function test_a_non_backed_enum_is_rendered_rather_than_dropped(): void
    {
        [, $payload] = $this->envelopeCarrying(NonBackedTraceEnum::Alpha);

        $this->assertSame(
            NonBackedTraceEnum::class.'::Alpha',
            $payload['debug']['trace'][0]['args'][0] ?? null,
        );
    }

    public function test_a_backed_enum_still_renders_as_its_value(): void
    {
        [, $payload] = $this->envelopeCarrying(BackedTraceEnum::Beta);

        $this->assertSame('beta', $payload['debug']['trace'][0]['args'][0] ?? null);
    }

    public function test_a_closure_in_a_trace_argument_does_not_replace_the_error(): void
    {
        [$status, $payload] = $this->envelopeCarrying(fn () => 'x');

        $this->assertSame(500, $status);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    public function test_a_resource_in_a_trace_argument_does_not_replace_the_error(): void
    {
        $handle = fopen('php://memory', 'r');

        try {
            [$status, $payload] = $this->envelopeCarrying($handle);
        } finally {
            fclose($handle);
        }

        $this->assertSame(500, $status);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    public function test_a_recursive_structure_does_not_replace_the_error(): void
    {
        $node = new RecursiveTraceNode;
        $node->self = $node;

        [$status, $payload] = $this->envelopeCarrying($node);

        $this->assertSame(500, $status);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    public function test_an_object_whose_json_serialize_throws_does_not_replace_the_error(): void
    {
        [$status, $payload] = $this->envelopeCarrying(new ThrowingTraceArgument);

        $this->assertSame(500, $status);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    public function test_an_invalid_utf8_byte_string_does_not_replace_the_error(): void
    {
        [$status, $payload] = $this->envelopeCarrying("\xB1\x31 raw bytes");

        $this->assertSame(500, $status);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    /**
     * The half the defence used to miss. `toResponseArray()` is spatie's `toArray()` — a transformer
     * pipeline over live property values, so it can throw for the same reasons the ENCODER can, and
     * it used to be evaluated at the call site, outside the try. Stage 0 pulls it inside: the
     * projection is handed in as a closure, and when it throws the object's own declared state is
     * recovered instead. The message still reaches whoever has to read it.
     */
    public function test_a_projection_that_throws_does_not_replace_the_error(): void
    {
        $body = new ProjectionThatThrows(
            success: false,
            statusCode: ResponseBody::HTTP_SERVER_ERROR,
            message: 'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
        );

        $response = $body->toResponse(null);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    /**
     * The recovery must respect the projection's FILTER, not just its transformation.
     *
     * `debug` holds the stack trace and the SQL, and `toResponseArray()` drops it unless
     * `app.debug`. The first cut of stage 0 recovered state with `get_object_vars($this)`, which
     * runs in object scope and returns every property regardless — so a projection failure would
     * have published that slot in production, on the error path, which is the exact payload and the
     * exact moment this trait exists to protect. Found in review, before it shipped anywhere.
     */
    public function test_a_failed_projection_does_not_leak_debug_when_debug_is_off(): void
    {
        config()->set('app.debug', false);

        $body = new ProjectionThatThrows(
            success: false,
            statusCode: ResponseBody::HTTP_SERVER_ERROR,
            message: 'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
        );
        $body->debug = ['sql' => 'select * from users where token = ?', 'trace' => 'SENSITIVE'];

        $payload = json_decode($body->toResponse(null)->getContent(), true);

        $this->assertArrayNotHasKey('debug', $payload);
        $this->assertArrayNotHasKey('_additional', $payload);
        $this->assertArrayNotHasKey('_dataContext', $payload);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
    }

    /**
     * The lifted `exception()` factory (api-surface-coherence 130) is the one member of this class
     * whose ARGUMENT is a throwable, so it is the member most likely to be handed the thing this
     * whole trait exists to survive. Nine hosts are about to route their error path through it.
     */
    public function test_the_exception_factory_survives_an_unencodable_trace_argument(): void
    {
        $thrown = new RuntimeException('SQLSTATE[42P01]: relation "beam_hooks" does not exist');

        $body = ResponseBody::exception($thrown)
            ->withDebug(['trace' => [['function' => 'handle', 'args' => [NonBackedTraceEnum::Alpha]]]]);

        $response = $body->toResponse(null);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame(
            'SQLSTATE[42P01]: relation "beam_hooks" does not exist',
            $payload['message'] ?? null,
        );
        $this->assertSame(
            NonBackedTraceEnum::class.'::Alpha',
            $payload['debug']['trace'][0]['args'][0] ?? null,
        );
    }

    public function test_an_ordinary_payload_is_untouched(): void
    {
        $body = ResponseBody::from(['data' => ['id' => 7, 'name' => 'ok']]);

        $payload = json_decode($body->toResponse(null)->getContent(), true);

        $this->assertSame(['id' => 7, 'name' => 'ok'], $payload['data']);
        $this->assertTrue($payload['success']);
    }
}

enum NonBackedTraceEnum
{
    case Alpha;
}

enum BackedTraceEnum: string
{
    case Beta = 'beta';
}

class RecursiveTraceNode
{
    public ?RecursiveTraceNode $self = null;
}

class ThrowingTraceArgument implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('nope');
    }
}

/**
 * A ResponseBody whose PROJECTION fails, rather than whose payload does.
 *
 * `toResponseArray()` wraps spatie's `toArray()` — a transformer pipeline over live property values,
 * which can throw for the same reasons the encoder can. Before stage 0 it was evaluated at the call
 * site, so this failure escaped the guarantee entirely: the envelope built to report an error died
 * producing itself, and the ORIGINAL message never reached anyone. Throwing outright is the blunt
 * form of what a real transformer failure does mid-pipeline.
 */
class ProjectionThatThrows extends ResponseBody
{
    public function toResponseArray(?string $route = null): array
    {
        throw new RuntimeException('the projection itself failed');
    }
}
