<?php

namespace Splicewire\Beam\Write;

use Schemastud\DataSchemas\Migration\AcceptanceGate;

/**
 * The CAPTURE-mode acceptance gate for the submission write path — it accepts every payload.
 *
 * A form submission is CAPTURE, not a validated CRUD write: intake accretion / completeness /
 * compliance-declaration features are built on storing PARTIAL and not-yet-conforming payloads
 * (a section captured with only one field, a re-proof that supersedes it later), and a submission
 * that does not conform to its current schema is meant to land and then be reconciled on READ
 * (migrate-on-read: `pending`/`failed` quarantine, ADR-0138), NOT rejected at write time.
 *
 * beam's {@see ParticleWriter} validates a write against the resolved target
 * schema via the default {@see AcceptanceGate}. That is correct for authenticated CRUD but WRONG for
 * submission capture — a caller typically validates the payload itself (for a human-facing error)
 * BEFORE calling the writer, so enforcing it again here would only reject partial/legitimate
 * captures the caller already accepted. This gate hands `ParticleWriter` an always-accept
 * acceptance check, exactly as {@see PermissiveWriteGate} does for authorization: the server-trusted
 * capture flow opts out of write-time schema enforcement deliberately, at the call site. The default
 * gate (deny/validate) is unchanged for every other write path.
 */
class PermissiveAcceptanceGate extends AcceptanceGate
{
    public function accepts(array $candidate, array $targetSchema): bool
    {
        return true;
    }
}
