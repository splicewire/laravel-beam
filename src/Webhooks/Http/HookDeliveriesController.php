<?php

namespace Splicewire\Beam\Webhooks\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\RequestLogs\Models\RequestLog;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Webhooks\Data\HookDeliveryData;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;
use Throwable;

/**
 * `GET /hooks/{hook}/deliveries` — what this subscription actually sent, read-only
 * (api-surface-coherence ticket 38, decided by 12 §6).
 *
 * ## It reads `request_logs`; there is no delivery table and there is not going to be one
 *
 * Ticket 37 deleted `webhook_deliveries` — one writer, zero production readers — and rehomed the
 * transport record onto `rushing/laravel-request-logs`, which logs every outbound HTTP call the host
 * makes. This endpoint is the reader that table never had.
 *
 * The join is `request_log_models`, the package's own morph pivot, populated because
 * {@see DispatchWebhookJob} registers the hook against the delivery uuid
 * before the call goes out.
 *
 * ## A host WITHOUT the package answers an empty list, not a 500
 *
 * `rushing/laravel-request-logs` is deliberately not a beam-core dependency (37: "the correct posture
 * for a beam-core edge that must carry no new dependency"). A bare beam host therefore has hooks that
 * deliver and no record of the deliveries, and the honest answer to "what did this hook send" is "no
 * rows", not an exception naming a package the operator chose not to install. This is ticket 91's
 * rule again: the answer depends on the host, so it must not throw.
 *
 * @group Platform
 */
class HookDeliveriesController extends Controller
{
    /**
     * Delivery attempts
     *
     * Every attempt this hook made, newest first, projected from the outbound request log. Carries
     * the delivery uuid the receiver saw, the status it answered with, and the timings — but no
     * body: see {@see HookDeliveryData} for why that half of 12 §6 is a recorded contradiction rather
     * than an omission.
     */
    #[ResponseFromData(HookDeliveryData::class, description: 'The attempts, newest first.')]
    public function index(Request $request, Hook $hook)
    {
        Gate::authorize('view', $hook);

        $limit = min(max((int) $request->integer('limit', 50), 1), 200);

        return ResponseBody::from([
            'data' => array_map(
                fn ($row) => HookDeliveryData::fromRequestLog($row),
                $this->rows($hook, $limit),
            ),
            'limit' => $limit,
        ]);
    }

    /**
     * The request-log rows linked to this hook, or none at all when the host did not install the
     * package that writes them.
     *
     * `class_exists` rather than a container check: the model is what this reads, and a host may have
     * the package installed with logging switched off — which still yields a real (empty) table and a
     * correct empty answer.
     *
     * @return list<object>
     */
    protected function rows(Hook $hook, int $limit): array
    {
        $model = config('request-logs.models.request_log', RequestLog::class);

        if (! is_string($model) || ! class_exists($model)) {
            return [];
        }

        try {
            return $model::query()
                ->whereIn('id', function ($query) use ($hook) {
                    $query->select('request_log_id')
                        ->from('request_log_models')
                        ->where('model_type', $hook->getMorphClass())
                        ->where('model_id', $hook->getKey());
                })
                ->orderByDesc('request_at')
                ->limit($limit)
                ->get()
                ->all();
        } catch (Throwable) {
            // The table is absent (package installed, migrations not published). Same answer as the
            // package being absent: no rows. An operator's diagnostic surface must not be the thing
            // that breaks when a diagnostic table is missing.
            return [];
        }
    }
}
