<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Submissions\RecordsSubmissions;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\Dedupe\DedupeDeclaration;
use Splicewire\Beam\Write\Dedupe\DedupeKey;
use Splicewire\Beam\Write\Dedupe\DedupeMode;
use Splicewire\Beam\Write\Dedupe\DedupeNotSupported;
use Splicewire\Beam\Write\Dedupe\DuplicateRejected;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\WriteContext;

/**
 * Stage 3 of the write chain (beam-facade tickets 50 §7 / 66): stamp the capture's match key and act
 * on `x-beam-dedupe` when the resolved target schema declares it. A no-op — the overwhelmingly
 * common case — when the record type has no schema or the schema carries no keyword.
 *
 * ## Precedence: authorize → validate → DEDUPE → persist
 *
 * The rule, not a detail: an unauthorized duplicate is a 403 and an invalid duplicate is a 422. The
 * dedupe verdict never preempts a gate, because a caller who may not write at all, or whose payload
 * does not conform, must not learn from a 409 that the address is already on the list.
 *
 * ## Why it is in the SHIPPED default chain and not host-passed
 *
 * {@see ParticleWriter}'s docblock offers idempotency as the archetypal
 * host-layered stage, and for dedupe that would not work:
 * {@see RecordsSubmissions} constructs its OWN 4-arg writer — the
 * default chain — and it is the path both hosts that want dedupe actually call, so a host-passed
 * stage would never fire for either of them. A schema-driven concern belongs where every write sees
 * it (ticket 50 §7).
 *
 * ## Notify is untouched, and that is easy to miss
 *
 * Under `admit` a repeat persists, {@see BeamParticlePersisted} fires, and a host composing
 * beam-notifications mails on every re-signup — which is what fable and entreport do today, so
 * `admit` is behaviour-preserving. Under `ignore` and `reject` NOTHING PERSISTS, so there is no
 * event and NO NOTIFICATION: those two modes silence the mail for any host composing
 * beam-notifications. `x-beam-dedupe` and `x-beam-notify` are otherwise uncoupled on purpose —
 * making one keyword's behaviour depend on another's presence is the interaction ticket 40 showed
 * is invisible until it misfires.
 *
 * @throws DedupeNotSupported the schema declares the keyword against a model that has not opted in
 * @throws DuplicateRejected `mode: reject` and the key matched (nothing persisted)
 */
class DedupeStage implements WriteStage
{
    public function __construct(private SchemaTargetResolver $targets) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        $recordType = $context->recordType();
        if ($recordType === null) {
            return $next($context);
        }

        $declaration = DedupeDeclaration::fromSchema($this->targets->targetFor($recordType));
        if ($declaration === null) {
            return $next($context);
        }

        $model = $context->model;
        if (! in_array(Deduplicates::class, class_uses_recursive($model), true)) {
            throw DedupeNotSupported::for($model);
        }

        // A missing (or empty, or non-scalar) declared field yields NO key — dedupe simply does not
        // apply to this capture, and the row lands unstamped. Never a key over the absence, which
        // would collide every such payload with every other (ticket 50 §8).
        $key = DedupeKey::compute($model->dedupeScope(), $declaration, $context->payload);
        if ($key === null) {
            return $next($context);
        }

        $model->setAttribute($model->dedupeKeyColumn(), $key);

        $first = $this->firstSeen($model, $key);
        if ($first === null) {
            return $next($context);
        }

        if ($declaration->mode === DedupeMode::Reject) {
            throw DuplicateRejected::for($model);
        }

        if ($declaration->mode === DedupeMode::Ignore) {
            // Abort the chain WITHOUT persisting or emitting, handing back the row that matched.
            // Returning the context rather than calling `$next` is what skips persist and emit; the
            // writer returns `$context->model`, so the caller receives the matched row and cannot
            // tell this apart from a fresh `admit` (ticket 50 §6 — a security property).
            $context->model = $first;

            return $context;
        }

        // Admit: the duplicate lands, linked back to the row it matched. `meta.dedupe.first_seen_id`
        // and no ordinal — an ordinal is derivable and would be wrong under concurrency.
        $meta = $model->getAttribute('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['dedupe'] = ['first_seen_id' => $first->getKey()];
        $model->setAttribute('meta', $meta);

        return $next($context);
    }

    /**
     * The earliest row carrying this key, on the model's own connection and table — a bare
     * `where(dedupe_key)` with no scoping clause, because the capture scope is folded INTO the hash
     * (ticket 50 §9), which is what makes the column globally comparable.
     *
     * Ordered by `created_at` then `id`: the uuid7 primary key is time-ordered, so it settles a
     * same-second tie deterministically rather than leaving the linkage to insertion luck.
     */
    private function firstSeen(Model $model, string $key): ?Model
    {
        return $model->newQuery()
            ->where($model->dedupeKeyColumn(), $key)
            ->orderBy('created_at')
            ->orderBy($model->getKeyName())
            ->first();
    }
}
