<?php

return [
    /*
     | Outbound webhook delivery. Defaults mirror the original provisioning job
     | (ADR-0039/0043) so B1's migration onto the generic dispatcher is a no-op
     | behavioral change. Retry count + backoff are here so operators can tune
     | delivery without a redeploy.
     |
     | Deliberately FLAT (`config/webhooks.php`, read as `webhooks.outbound.*`)
     | rather than nested under beam's `config/beam/*.php` convention. Two reasons,
     | both recorded in api-surface-coherence 37: the keys are a stated contract of
     | that ticket ("`webhooks.outbound.{tries,backoff,timeout}` do not change"),
     | and the footgun the nesting convention exists to avoid is specifically a
     | `config/beam.php` file sitting next to a `config/beam/` directory — which a
     | file named `webhooks.php` cannot cause. Nothing else in the estate claims
     | this filename (no spatie/laravel-webhook-* is installed anywhere).
     */
    'outbound' => [
        'tries' => (int) env('WEBHOOKS_OUTBOUND_TRIES', 5),
        'backoff' => [10, 30, 60, 120],
        'timeout' => (int) env('WEBHOOKS_OUTBOUND_TIMEOUT', 30),

        /*
         | The delivery-header namespace (api-surface-coherence 38, decided by ticket 12 §5).
         | `X-Beam-Signature`, `-Event`, `-Delivery`, `-Hook`. It is `X-Beam` and NOT
         | `X-Splicewire` deliberately: this is the FREE-TIER package, `Splicewire` is the paid
         | vendor name, and a bare beam host should not stamp a product it has not bought on
         | every request it sends. A host that white-labels overrides it here; a trailing `-`
         | is stripped, so both `X-Acme` and `X-Acme-` work.
         */
        'header_prefix' => env('WEBHOOKS_OUTBOUND_HEADER_PREFIX', 'X-Beam'),

        /*
         | Consecutive failed deliveries after which a hook auto-disables (`disabled_at`).
         | `op/reset` is the only way back. Counts CONSECUTIVE failures — any success zeroes it —
         | so this is "the endpoint is gone", not "the endpoint had a bad afternoon".
         */
        'failure_threshold' => (int) env('WEBHOOKS_OUTBOUND_FAILURE_THRESHOLD', 5),

        /*
         | Bytes of response body retained per delivery. The CAP is the bound; the host-configurable
         | RedactorInterface channel is applied on OUTPUT, not here (ticket 12 §6) — a redactor that
         | is swapped later must be able to re-redact what is already stored, which it cannot do if
         | storage already threw the bytes away. The cap is the one thing that must happen at store
         | time, because unbounded is unbounded no matter who reads it.
         */
        'response_body_cap' => (int) env('WEBHOOKS_OUTBOUND_RESPONSE_BODY_CAP', 8192),
    ],
];
