<?php

namespace Splicewire\Beam\Webhooks\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;

/**
 * One attempted delivery, projected out of `request_logs` for
 * `GET /hooks/{hook}/deliveries` (api-surface-coherence ticket 38, decided by 12 §6).
 *
 * ## There is no `body` on this shape, and that is a CONTRADICTION recorded, not a design
 *
 * Ticket 12 §6 specified a response body "capped at store time, projected through a
 * host-configurable `RedactorInterface` channel on output". Ticket 37 then rehomed the edge onto
 * `rushing/laravel-request-logs` and measured what that table actually holds: `request_id`, `path`,
 * `method`, `api_version`, `request_at`, `response_at`, `response_status`, `is_error` — and no
 * payload column at all. The bodies go to the Monolog channels (`request_logs_raw` /
 * `request_logs_redacted`), which are a log destination, not a queryable store.
 *
 * So the body half of 12 §6 is not servable from this join without inventing a second store — which
 * is precisely the improvisation 38's preamble forbids. The metadata half ships; the contradiction
 * is filed on ticket 12 rather than papered over with a field that would always be null.
 *
 * ## `id` is the delivery uuid, and that is why the join works at all
 *
 * {@see DispatchWebhookJob} sends the delivery uuid as the outbound
 * `X-Request-Id`, which `GuzzleRequestLogMiddleware` adopts verbatim as the row's `request_id`
 * rather than minting its own. The same uuid is the `Idempotency-Key` and the `X-Beam-Delivery`
 * header the receiver saw, so a support conversation that starts with a receiver quoting a delivery
 * id ends at exactly one row.
 */
#[TypeScript]
class HookDeliveryData extends Data
{
    public function __construct(
        #[Description('The delivery uuid — the same string the receiver saw as `Idempotency-Key` and `X-Beam-Delivery`.')]
        public string $id,

        #[Description('The path of the endpoint the delivery was POSTed to. The row stores the path, not the full URL.')]
        public ?string $path,

        #[Description('HTTP method — always POST for a hook delivery.')]
        public ?string $method,

        #[Description('The receiver\'s HTTP status, or 0 when the request never got a response at all.')]
        public ?int $status,

        #[Description('Whether the attempt is recorded as a failure.')]
        public bool $failed,

        #[Description('When the attempt left, ISO-8601.')]
        public ?string $requestedAt,

        #[Description('When the response landed, ISO-8601. Null when none did.')]
        public ?string $respondedAt,
    ) {}

    /** @param object $row a `rushing/laravel-request-logs` RequestLog */
    public static function fromRequestLog(object $row): self
    {
        return new self(
            id: (string) $row->request_id,
            path: $row->path === null ? null : (string) $row->path,
            method: $row->method === null ? null : (string) $row->method,
            status: $row->response_status === null ? null : (int) $row->response_status,
            failed: (bool) $row->is_error,
            requestedAt: $row->request_at?->toIso8601String(),
            respondedAt: $row->response_at?->toIso8601String(),
        );
    }
}
