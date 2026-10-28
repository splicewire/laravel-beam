<?php

namespace Splicewire\Beam\Rpc;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;
use Splicewire\Beam\Webhooks\HookSignature;
use stdClass;

/**
 * A synchronous, signed platform → satellite call: the transport a popcorn `RemoteInvocable` is built with
 * (`callable(string $name, array $input): array`) when the remote handler is a tenant's bound service
 * (music-capability-lift ticket 06 Q3, build issue 31).
 *
 * ## Why not `WebhookDispatcher`
 *
 * An RPC callback is not a webhook (ADR-0052 §2). `WebhookDispatcher` only queues, and its job discards the
 * response, so it can never return the answer an invocable needs. This transport posts inline and returns
 * the decoded response body.
 *
 * ## The signature is {@see HookSignature}, unchanged
 *
 * The body is encoded ONCE and posted with `withBody()`, so the bytes signed are the bytes sent — the rule
 * {@see DispatchWebhookJob} documents. Each attempt carries `X-Beam-Signature: t=…,v1=…` (re-signed per attempt,
 * so a retry carries a fresh `t` over the same bytes), `X-Beam-Delivery` (one uuid per invocation, the same
 * on its retry) and `Idempotency-Key` (the delivery uuid unless the caller derives a deterministic one, as
 * ticket 06 Q2 does with sha256(conduit id | intent digest)). The receiver half is laravel-satellite's
 * `VerifySignedRpc`.
 *
 * The secret is resolved at invocation, never at construction, so a transport built during hydration reads
 * the binding's current secret when it actually sends (ticket 06 Q1).
 *
 * ## The response is not signed
 *
 * In production it comes back over the verified TLS connection this transport opened to the bound endpoint;
 * TLS is verified when `app()->isProduction()`, on {@see DispatchWebhookJob::verifiesTls()}'s posture, and
 * skipped elsewhere (self-signed `*.test`). Locally the response has no authenticity, which is accepted for
 * development (ticket 06 Q3).
 *
 * ## Failures are typed and kept apart (ticket 06 Q2)
 *
 * - 422 → {@see SignedRpcRefused}: a refusal, never retried.
 * - 401 / 403 → {@see SignedRpcBindingFault}: the binding (secret or endpoint) is wrong, never retried.
 * - connect error, timeout, 5xx, 429 → {@see SignedRpcTransientFault}, after `$retries` further attempts
 *   {@see RETRY_DELAY_SECONDS} apart.
 * - any other status, or a 2xx whose body is not a JSON object or array → {@see SignedRpcUnexpectedResponse},
 *   never retried.
 *
 * A 2xx with an empty body (the probe's 204) returns `[]`.
 *
 * ## The budget is explicit
 *
 * Connect 5 s, total 20 s per attempt and one transient retry are ticket 06 Q2's nominations for the Arranger.
 * A caller with another budget passes its own: a circuit palette binding runs 10 s / 30 s with `retries: 0`,
 * because an arbitrary action is not known to be idempotent (ticket 14 Q7).
 */
final class SignedRpcTransport
{
    /** Seconds between a transient fault and its retry (ticket 06 Q2). */
    public const RETRY_DELAY_SECONDS = 2;

    /** @var Closure(): (string|null) */
    private Closure $secret;

    /**
     * @param  string|Closure(): (string|null)  $secret  the HMAC key, or a resolver read at each invocation
     * @param  (Closure(string, array<array-key, mixed>, string): string)|null  $idempotencyKey  derives `Idempotency-Key`
     *                                                                                           from (name, input, encoded body); null uses the delivery uuid
     * @param  int|float  $connectTimeout  seconds to establish the connection, per attempt
     * @param  int|float  $timeout  seconds for the whole attempt
     * @param  int  $retries  further attempts after a transient fault (never after a 422 or 401/403)
     * @param  array<string, string>  $headers  extra request headers (e.g. the probe header); the signing headers always win
     */
    public function __construct(
        private readonly string $endpoint,
        #[SensitiveParameter] string|Closure $secret,
        private readonly ?Closure $idempotencyKey = null,
        private readonly int|float $connectTimeout = 5,
        private readonly int|float $timeout = 20,
        private readonly int $retries = 1,
        private readonly array $headers = [],
    ) {
        if ($retries < 0) {
            throw new InvalidArgumentException('SignedRpcTransport retries must be zero or more.');
        }

        $this->secret = $secret instanceof Closure ? $secret : static fn (): string => $secret;
    }

    /**
     * The header a platform probe carries (`X-Beam-Probe: 1`); a verifying receiver answers a signed probe
     * 204 before its route (ticket 14 Q6).
     */
    public static function probeHeader(): string
    {
        return HookSignature::header('probe');
    }

    /**
     * TLS is verified in production and skipped elsewhere (self-signed *.test) — the same posture as
     * {@see DispatchWebhookJob::verifiesTls()}.
     */
    public function verifiesTls(): bool
    {
        return app()->isProduction();
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     *
     * @throws SignedRpcFailure
     */
    public function __invoke(string $name, array $input): array
    {
        $secret = ($this->secret)();

        if (! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException("SignedRpcTransport for [{$name}] resolved no signing secret; nothing was sent.");
        }

        $body = self::encode($input);
        $delivery = (string) Str::uuid();
        $idempotencyKey = $this->idempotencyKey !== null
            ? ($this->idempotencyKey)($name, $input, $body)
            : $delivery;

        $attempts = 0;

        while (true) {
            $attempts++;
            $retryable = $attempts <= $this->retries;

            try {
                $response = $this->request($body, $secret, $delivery, $idempotencyKey)->post($this->endpoint);
            } catch (ConnectionException $e) {
                if ($retryable) {
                    Sleep::for(self::RETRY_DELAY_SECONDS)->seconds();

                    continue;
                }

                throw new SignedRpcTransientFault($name, $this->endpoint, $attempts, null, null, $e->getMessage(), $e);
            }

            if ($response->successful()) {
                return $this->decode($name, $response, $attempts);
            }

            $status = $response->status();

            if ($status === 422) {
                throw new SignedRpcRefused($name, $this->endpoint, $attempts, $status, self::json($response));
            }

            if ($status === 401 || $status === 403) {
                throw new SignedRpcBindingFault($name, $this->endpoint, $attempts, $status, self::json($response));
            }

            if ($status === 429 || $response->serverError()) {
                if ($retryable) {
                    Sleep::for(self::RETRY_DELAY_SECONDS)->seconds();

                    continue;
                }

                throw new SignedRpcTransientFault($name, $this->endpoint, $attempts, $status, self::json($response));
            }

            throw new SignedRpcUnexpectedResponse($name, $this->endpoint, $attempts, $status, self::json($response));
        }
    }

    private function request(string $body, string $secret, string $delivery, string $idempotencyKey): PendingRequest
    {
        $signing = [
            HookSignature::header('signature') => HookSignature::sign($body, $secret),
            HookSignature::header('delivery') => $delivery,
            'Idempotency-Key' => $idempotencyKey,
        ];

        // Header names are case-insensitive: drop any caller header that names a signing one, in any case.
        $reserved = array_map(strtolower(...), array_keys($signing));
        $extra = array_filter(
            $this->headers,
            static fn (string $name): bool => ! in_array(strtolower($name), $reserved, true),
            ARRAY_FILTER_USE_KEY,
        );

        $request = Http::acceptJson()
            ->withHeaders([...$extra, ...$signing])
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);

        if (! $this->verifiesTls()) {
            $request = $request->withoutVerifying();
        }

        return $request->withBody($body, 'application/json');
    }

    /**
     * The one encoding of the input. An empty input is the empty OBJECT `{}` — the body is a JSON object on
     * the wire, and `json_encode([])` would send `[]`.
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function encode(array $input): string
    {
        return json_encode(
            $input === [] ? new stdClass : $input,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<array-key, mixed> */
    private function decode(string $name, Response $response, int $attempts): array
    {
        if (trim($response->body()) === '') {
            return [];
        }

        $decoded = self::json($response);

        if ($decoded === null) {
            throw new SignedRpcUnexpectedResponse($name, $this->endpoint, $attempts, $response->status(), null, 'the 2xx body is not a JSON object or array');
        }

        return $decoded;
    }

    /** @return array<array-key, mixed>|null */
    private static function json(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
