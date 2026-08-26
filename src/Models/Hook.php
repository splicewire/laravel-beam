<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Facades\Beam;

/**
 * One event subscription: a set of catalog event names, an endpoint, and the secret every delivery
 * to it is signed with (api-surface-coherence ticket 38, decided by ticket 12).
 *
 * The table name routes through the single beam table-prefix seam ({@see Beam::table()}), same as
 * {@see BeamParticle} and {@see GitRepo}, so a retrofit host's one prefix override follows.
 *
 * ## Two off-switches, and only one of them has a way back
 *
 * {@see $paused_at} is user intent and {@see $disabled_at} is system health (ticket 12 §4);
 * {@see deliverable()} requires both null. `op/reset` is the only path out of `disabled_at` — it
 * zeroes {@see $consecutive_failures} and clears the flag — and it deliberately does NOT clear
 * `paused_at`, because a hook paused by a lapsed entitlement must come back when the plan does, not
 * when the user finds a button (ticket 13 §4).
 *
 * ## The resource is not stored
 *
 * A hook has no resource column. `resourceKeys()` reads it off the subscribed names, each of whose
 * first segment IS the resource key ({@see EventType::resourceKey()}). That is what keeps `hooks`
 * from becoming a fourth stored-name case for ticket 16 to rot, and it is why a hook may span two
 * resources without a schema change.
 *
 * House style: plain mutable class — no `strict_types`, no `final`, no `readonly`.
 *
 * @property string $id
 * @property string $endpoint
 * @property string $secret
 * @property string|null $token
 * @property array<int, string> $events
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property int $consecutive_failures
 * @property string|null $last_failure_request_log_id
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property array<int, string>|null $entitlement_keys
 */
class Hook extends Model
{
    use HasUuids;

    /**
     * Consecutive failures after which delivery auto-disables. Host-overridable; the default matches
     * `webhooks.outbound.tries` being exhausted five times over, which is a genuinely dead endpoint
     * rather than a bad afternoon.
     */
    public const DEFAULT_FAILURE_THRESHOLD = 5;

    protected $fillable = [
        'endpoint',
        'secret',
        'token',
        'events',
        'subject_type',
        'subject_id',
        'paused_at',
        'disabled_at',
        'consecutive_failures',
        'last_failure_request_log_id',
        'verified_at',
        'entitlement_keys',
        'owner_type',
        'owner_id',
    ];

    /**
     * The minted secret is write-only on the wire: {@see HookData} projects `secretPreview` and the
     * reveal-once create response carries the real value exactly once. Hiding it here means an
     * accidental `->toArray()` anywhere cannot leak it.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    public function getTable(): string
    {
        return Beam::table('hooks');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'entitlement_keys' => 'array',
            'paused_at' => 'datetime',
            'disabled_at' => 'datetime',
            'verified_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    // ── State ───────────────────────────────────────────────────────────────────────────────────────

    /** Both off-switches clear. The FEATURE plane is re-checked separately, at emission (ticket 13 §2). */
    public function deliverable(): bool
    {
        return $this->paused_at === null && $this->disabled_at === null;
    }

    /** Does this hook subscribe to `$eventName`? Exact name match — the catalog is the vocabulary. */
    public function subscribesTo(string $eventName): bool
    {
        return in_array($eventName, (array) $this->events, true);
    }

    /**
     * The resource keys this hook spans — segment one of each subscribed name, deduped.
     *
     * @return list<string>
     */
    public function resourceKeys(): array
    {
        $keys = [];

        foreach ((array) $this->events as $name) {
            $key = (new EventType(name: (string) $name, subjectless: true))->resourceKey();

            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Record a failed delivery, auto-disabling once the threshold is crossed. Returns true when THIS
     * call is the one that disabled it — the caller emits `hooks.disabled` off that, so a hook that
     * is already disabled does not re-announce itself on every retry.
     */
    public function recordFailure(?string $requestLogId = null): bool
    {
        $this->consecutive_failures = $this->consecutive_failures + 1;
        $this->last_failure_request_log_id = $requestLogId;

        $justDisabled = false;

        if ($this->disabled_at === null && $this->consecutive_failures >= static::failureThreshold()) {
            $this->disabled_at = now();
            $justDisabled = true;
        }

        $this->save();

        return $justDisabled;
    }

    /** Any success zeroes the counter — the threshold counts CONSECUTIVE failures, not lifetime ones. */
    public function recordSuccess(): void
    {
        if ($this->consecutive_failures === 0 && $this->last_failure_request_log_id === null) {
            return;
        }

        $this->consecutive_failures = 0;
        $this->last_failure_request_log_id = null;
        $this->save();
    }

    /**
     * `op/reset`'s whole body: the only path out of auto-disable. Leaves `paused_at` alone on purpose
     * — see the class docblock.
     */
    public function reset(): void
    {
        $this->consecutive_failures = 0;
        $this->last_failure_request_log_id = null;
        $this->disabled_at = null;
        $this->save();
    }

    public static function failureThreshold(): int
    {
        return (int) config('webhooks.outbound.failure_threshold', self::DEFAULT_FAILURE_THRESHOLD);
    }

    /** A fresh signing secret. 64 hex chars off a CSPRNG — see {@see \Splicewire\Beam\Webhooks\HookSignature}. */
    public static function mintSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** What a list projection may show: enough to recognise the secret you saved, not enough to use it. */
    public function secretPreview(): string
    {
        return Str::limit($this->secret, 8, '…');
    }

    // ── Queries ─────────────────────────────────────────────────────────────────────────────────────

    /** @param  Builder<Hook>  $query */
    public function scopeDeliverable(Builder $query): void
    {
        $query->whereNull('paused_at')->whereNull('disabled_at');
    }

    /**
     * Every deliverable hook subscribed to `$eventName`, optionally narrowed to hooks that either
     * name this subject or name none at all.
     *
     * The `events` membership test is a JSON containment scan rather than a join table: the column is
     * a short list a human typed, the row count is per-tenant, and a join table would need its own
     * normalisation story for exactly the names ticket 16 already normalises once, in the catalog.
     *
     * @param  Builder<Hook>  $query
     */
    public function scopeSubscribedTo(Builder $query, string $eventName, ?Model $subject = null): void
    {
        $query->deliverable()->whereJsonContains('events', $eventName);

        if ($subject !== null) {
            $query->where(function (Builder $q) use ($subject) {
                $q->whereNull('subject_type')
                    ->orWhere(function (Builder $exact) use ($subject) {
                        $exact->where('subject_type', $subject->getMorphClass())
                            ->where('subject_id', $subject->getKey());
                    });
            });
        }
    }
}
