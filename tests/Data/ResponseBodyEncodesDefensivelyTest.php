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
