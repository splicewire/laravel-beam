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
    ],
];
