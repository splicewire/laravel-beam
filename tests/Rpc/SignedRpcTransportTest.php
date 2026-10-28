<?php

namespace Splicewire\Beam\Tests\Rpc;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Invocables\RemoteInvocable;
use Splicewire\Beam\Rpc\SignedRpcBindingFault;
use Splicewire\Beam\Rpc\SignedRpcFailure;
use Splicewire\Beam\Rpc\SignedRpcRefused;
use Splicewire\Beam\Rpc\SignedRpcTransientFault;
use Splicewire\Beam\Rpc\SignedRpcTransport;
use Splicewire\Beam\Rpc\SignedRpcUnexpectedResponse;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\HookSignature;

/**
 * music-capability-lift build issue 31 (ticket 06 Q2/Q3, ticket 14 Q6/Q7): the platform → satellite signed
 * RPC transport. The receiving side here is `HookSignature::verify` over the exact recorded request, the
 * same call laravel-satellite's `VerifySignedRpc` makes.
 */
class SignedRpcTransportTest extends TestCase
{
    private const ENDPOINT = 'https://audiostud.test/api/splicewire/invocables/music.render.midi';

    private const SECRET = 'pending-secret-0123456789abcdef';

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $sent = [];

    /** @var list<mixed> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        $this->sent = [];
        $this->responses = [];

        // One stub for the whole test: each attempt is recorded with its Guzzle options and answered
        // from the queue receiver() sets.
        Http::fake(function (Request $request, array $options) {
            $this->sent[] = ['request' => $request, 'options' => $options];
            $next = array_shift($this->responses);

            return $next instanceof \Closure ? $next($request) : $next;
        });
    }

    /**
     * Answer the next attempts from `$responses` in order.
     *
     * @param  list<mixed>  $responses  each a response (Http::response(...)) or a Closure answering it
     */
    private function receiver(array $responses): void
    {
        $this->responses = $responses;
    }

    private function transport(array $overrides = []): SignedRpcTransport
    {
        return new SignedRpcTransport(...array_merge([
            'endpoint' => self::ENDPOINT,
            'secret' => self::SECRET,
        ], $overrides));
    }

    private function verifies(Request $request, string $secret = self::SECRET): bool
    {
        return HookSignature::verify($request->header('X-Beam-Signature')[0] ?? '', $request->body(), $secret);
    }

    public function test_a_signed_request_verifies_on_the_receiving_side_over_the_bytes_sent(): void
    {
        $this->receiver([Http::response(['midi_base64' => 'TVRoZA==', 'bytes' => 4])]);

        // Slashes and non-ASCII make a re-encoded body differ from the sent one, so this proves the
        // signature covers the bytes on the wire rather than some other encoding of the same array.
        $input = ['intent' => ['title' => 'Here She Comes — ½ time', 'path' => 'a/b'], 'iteration' => 1];

        $result = ($this->transport())('music.render.midi', $input);

        $this->assertSame(['midi_base64' => 'TVRoZA==', 'bytes' => 4], $result);
        $this->assertCount(1, $this->sent);

        $request = $this->sent[0]['request'];

        $this->assertSame('POST', $request->method());
        $this->assertSame(self::ENDPOINT, $request->url());
        $this->assertSame(SignedRpcTransport::encode($input), $request->body());
        $this->assertSame($input, json_decode($request->body(), true));
        $this->assertTrue($this->verifies($request));
        $this->assertFalse($this->verifies($request, 'some-other-secret'));

        // The same data re-encoded with default flags is different bytes, and does not verify.
        $reEncoded = json_encode($input);
        $this->assertNotSame($request->body(), $reEncoded);
        $this->assertFalse(HookSignature::verify($request->header('X-Beam-Signature')[0], $reEncoded, self::SECRET));

        $this->assertStringStartsWith('application/json', $request->header('Content-Type')[0]);
        $this->assertSame('application/json', $request->header('Accept')[0]);
    }

    public function test_it_sends_a_delivery_uuid_and_an_idempotency_key(): void
    {
        $this->receiver([Http::response([]), Http::response([])]);

        ($this->transport())('music.render.midi', ['a' => 1]);

        $request = $this->sent[0]['request'];
        $delivery = $request->header('X-Beam-Delivery')[0];

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $delivery);
        $this->assertSame($delivery, $request->header('Idempotency-Key')[0], 'the default key is the delivery uuid');

        // A caller-derived key (ticket 06 Q2: sha256(conduit id | intent digest)) is sent as given.
        $derived = fn (string $name, array $input, string $body): string => hash('sha256', 'conduit-7|'.$name.'|'.$body);
        ($this->transport(['idempotencyKey' => $derived]))('music.render.midi', ['a' => 1]);

        $this->assertSame(hash('sha256', 'conduit-7|music.render.midi|{"a":1}'), $this->sent[1]['request']->header('Idempotency-Key')[0]);
        $this->assertNotSame($delivery, $this->sent[1]['request']->header('X-Beam-Delivery')[0], 'one delivery uuid per invocation');
    }

    public function test_the_secret_is_read_at_invocation_not_construction(): void
    {
        $this->receiver([Http::response([]), Http::response([])]);

        $reads = 0;
        $secret = self::SECRET;
        $transport = $this->transport(['secret' => function () use (&$reads, &$secret): string {
            $reads++;

            return $secret;
        }]);

        $this->assertSame(0, $reads);

        $transport('x', []);
        $secret = 'rotated-secret';
        $transport('x', []);

        $this->assertSame(2, $reads);
        $this->assertTrue($this->verifies($this->sent[0]['request'], self::SECRET));
        $this->assertTrue($this->verifies($this->sent[1]['request'], 'rotated-secret'));
    }

    public function test_an_empty_secret_sends_nothing(): void
    {
        $this->receiver([]);

        foreach ([fn (): ?string => null, fn (): string => ''] as $secret) {
            $e = $this->failure(fn () => ($this->transport(['secret' => $secret]))('x', []));

            $this->assertInstanceOf(SignedRpcBindingFault::class, $e);
            $this->assertSame(0, $e->attempts);
            $this->assertNull($e->status);
        }

        $this->assertSame([], $this->sent);
    }

    public function test_tls_is_verified_under_production_and_skipped_otherwise(): void
    {
        $this->receiver([Http::response([]), Http::response([])]);

        $this->assertFalse($this->app->isProduction());
        $this->assertFalse($this->transport()->verifiesTls());
        ($this->transport())('x', []);
        $this->assertFalse($this->sent[0]['options']['verify']);

        $this->app['env'] = 'production';

        $this->assertTrue($this->transport()->verifiesTls());
        ($this->transport())('x', []);
        $this->assertNotFalse($this->sent[1]['options']['verify'] ?? true, 'production must not disable verification');
    }

    public function test_timeouts_are_explicit_and_default_to_connect_5_total_20(): void
    {
        $this->receiver([Http::response([]), Http::response([])]);

        ($this->transport())('x', []);
        ($this->transport(['connectTimeout' => 10, 'timeout' => 30]))('x', []);

        $this->assertSame(5, $this->sent[0]['options']['connect_timeout']);
        $this->assertSame(20, $this->sent[0]['options']['timeout']);
        $this->assertSame(10, $this->sent[1]['options']['connect_timeout']);
        $this->assertSame(30, $this->sent[1]['options']['timeout']);
    }

    public function test_a_422_is_a_refusal_and_never_retried(): void
    {
        $refusal = ['reason' => 'unsupported_intent_members', 'members' => ['pickup', 'changes'], 'detail' => null];
        $this->receiver([Http::response($refusal, 422), Http::response([])]);

        $e = $this->failure(fn () => ($this->transport())('music.render.midi', ['a' => 1]));

        $this->assertInstanceOf(SignedRpcRefused::class, $e);
        $this->assertSame(422, $e->status);
        $this->assertSame($refusal, $e->body);
        $this->assertSame(1, $e->attempts);
        $this->assertCount(1, $this->sent);
        Sleep::assertNeverSlept();
    }

    public function test_a_401_or_403_is_a_binding_fault_and_never_retried(): void
    {
        foreach ([401, 403] as $status) {
            $this->sent = [];
            $this->receiver([Http::response(['message' => 'Invalid signature.'], $status), Http::response([])]);

            $e = $this->failure(fn () => ($this->transport())('x', []));

            $this->assertInstanceOf(SignedRpcBindingFault::class, $e);
            $this->assertSame($status, $e->status);
            $this->assertCount(1, $this->sent);
        }

        Sleep::assertNeverSlept();
    }

    public function test_a_5xx_retries_once_after_two_seconds_with_the_same_delivery(): void
    {
        $this->receiver([Http::response(['message' => 'down'], 503), Http::response(['ok' => true])]);

        $result = ($this->transport())('x', ['a' => 1]);

        $this->assertSame(['ok' => true], $result);
        $this->assertCount(2, $this->sent);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);

        [$first, $second] = [$this->sent[0]['request'], $this->sent[1]['request']];

        $this->assertSame($first->body(), $second->body());
        $this->assertSame($first->header('X-Beam-Delivery'), $second->header('X-Beam-Delivery'));
        $this->assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));
        $this->assertTrue($this->verifies($first));
        $this->assertTrue($this->verifies($second));
    }

    public function test_a_second_5xx_is_a_transient_fault_after_two_attempts(): void
    {
        $this->receiver([Http::response([], 500), Http::response([], 502), Http::response([])]);

        $e = $this->failure(fn () => ($this->transport())('x', []));

        $this->assertInstanceOf(SignedRpcTransientFault::class, $e);
        $this->assertSame(502, $e->status);
        $this->assertSame(2, $e->attempts);
        $this->assertCount(2, $this->sent);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_429_is_transient(): void
    {
        $this->receiver([Http::response([], 429), Http::response([], 429)]);

        $e = $this->failure(fn () => ($this->transport())('x', []));

        $this->assertInstanceOf(SignedRpcTransientFault::class, $e);
        $this->assertSame(429, $e->status);
        $this->assertCount(2, $this->sent);
    }

    public function test_a_connect_error_or_timeout_is_transient_with_no_status(): void
    {
        $this->receiver([
            Factory::failedConnection('cURL error 7: Failed to connect'),
            Factory::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds'),
        ]);

        $e = $this->failure(fn () => ($this->transport())('x', []));

        $this->assertInstanceOf(SignedRpcTransientFault::class, $e);
        $this->assertNull($e->status);
        $this->assertSame(2, $e->attempts);
        $this->assertStringContainsString('timed out', $e->getMessage());
        $this->assertCount(2, $this->sent);
        Sleep::assertSleptTimes(1);
    }

    public function test_retries_zero_makes_exactly_one_request(): void
    {
        $this->receiver([Http::response([], 503), Http::response([])]);

        $e = $this->failure(fn () => ($this->transport(['retries' => 0]))('x', []));

        $this->assertInstanceOf(SignedRpcTransientFault::class, $e);
        $this->assertSame(1, $e->attempts);
        $this->assertCount(1, $this->sent);
        Sleep::assertNeverSlept();
    }

    public function test_an_unclassified_status_or_a_non_json_2xx_is_unexpected_and_never_retried(): void
    {
        $this->receiver([Http::response('missing', 404), Http::response([])]);

        $e = $this->failure(fn () => ($this->transport())('x', []));
        $this->assertInstanceOf(SignedRpcUnexpectedResponse::class, $e);
        $this->assertSame(404, $e->status);
        $this->assertCount(1, $this->sent);

        $this->sent = [];
        $this->receiver([Http::response('<html>ok</html>', 200)]);

        $e = $this->failure(fn () => ($this->transport())('x', []));
        $this->assertInstanceOf(SignedRpcUnexpectedResponse::class, $e);
        $this->assertSame(200, $e->status);
        Sleep::assertNeverSlept();
    }

    public function test_a_probe_sends_an_empty_object_with_the_probe_header_and_reads_a_204_as_empty(): void
    {
        $this->receiver([Http::response(null, 204)]);

        $result = ($this->transport(['headers' => [
            SignedRpcTransport::probeHeader() => '1',
            // A caller-supplied signing header never wins over the transport's own.
            'X-Beam-Signature' => 't=1,v1=forged',
            'x-beam-delivery' => 'forged-delivery',
        ]]))('remote-invocable#7', []);

        $this->assertSame([], $result);

        $request = $this->sent[0]['request'];

        $this->assertSame('X-Beam-Probe', SignedRpcTransport::probeHeader());
        $this->assertSame('1', $request->header('X-Beam-Probe')[0]);
        $this->assertSame('{}', $request->body());
        $this->assertTrue($this->verifies($request));
        $this->assertCount(1, $request->header('X-Beam-Signature'));
        $this->assertCount(1, $request->header('X-Beam-Delivery'));
        $this->assertNotSame('forged-delivery', $request->header('X-Beam-Delivery')[0]);
    }

    public function test_it_is_the_transport_of_a_popcorn_remote_invocable(): void
    {
        $this->receiver([Http::response(['arranged' => true])]);

        $invocable = new RemoteInvocable('music.render.midi', Binding::Webhook, $this->transport(), self::ENDPOINT);

        $this->assertSame(['arranged' => true], $invocable->invoke(['intent' => []]));
        $this->assertSame(self::ENDPOINT, $invocable->endpoint());
        $this->assertTrue($this->verifies($this->sent[0]['request']));
    }

    private function failure(callable $call): SignedRpcFailure
    {
        try {
            $call();
        } catch (SignedRpcFailure $e) {
            return $e;
        }

        $this->fail('expected a SignedRpcFailure');
    }
}
