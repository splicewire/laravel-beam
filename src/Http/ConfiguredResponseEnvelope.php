<?php

namespace Splicewire\Beam\Http;

use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Surgeon\ResponseEnvelopeAudit;

/**
 * The ONE reading of `beam.core.http.envelope` — what the container binds, and what the audit reports.
 *
 * ## Why this is a class and not two `is_a()` calls
 *
 * The rule has two halves — *is the configured value usable*, and *what is served when it is not* — and
 * both halves were written twice: once inside `BeamServiceProvider`'s binding closure and once inside
 * {@see ResponseEnvelopeAudit}. Two copies of that rule can drift, and the drift is silent in the worst
 * possible direction: the audit would report a wire shape the runtime does not serve, on a key that
 * exists precisely so a host can be told which shape it serves. One reader, two callers.
 *
 * ## The fallback is deliberate, and it is why `usable()` exists separately
 *
 * An unusable class-string resolves to {@see ArrayResponseEnvelope} rather than throwing, because a
 * mistyped key must not take the whole particle surface down. The cost is that the failure is SILENT at
 * runtime — a host expecting `{ success, message, data }` quietly gets `{ data }` behind working
 * responses. So the audit asks {@see usable()} rather than inferring from {@see resolve()}, which cannot
 * distinguish "configured neutral" from "fell back to neutral".
 */
class ConfiguredResponseEnvelope
{
    /** The config key this reads. Named once so a rename cannot half-land. */
    public const KEY = 'beam.core.http.envelope';

    /** The shape a host gets with no wiring at all — and the fallback for an unusable value. */
    public const DEFAULT = ArrayResponseEnvelope::class;

    /** The raw configured value, whatever it is — the audit reports on this, so it is not coerced. */
    public static function configured(): mixed
    {
        return config(self::KEY, self::DEFAULT);
    }

    /** Whether the configured value names something the container can hand out as a ResponseEnvelope. */
    public static function usable(mixed $value): bool
    {
        return is_string($value) && is_a($value, ResponseEnvelope::class, true);
    }

    /**
     * The class-string that will actually be served: the configured one when usable, the neutral default
     * otherwise.
     *
     * @return class-string<ResponseEnvelope>
     */
    public static function resolve(): string
    {
        $configured = self::configured();

        return self::usable($configured) ? $configured : self::DEFAULT;
    }
}
