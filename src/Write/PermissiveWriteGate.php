<?php

namespace Splicewire\Beam\Write;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * The SERVER-TRUSTED-caller binding of beam's {@see WriteGate} — an explicit, always-authorizing
 * opt-in for write flows that have ALREADY authorized upstream.
 *
 * Beam's default binding ({@see GateWriteGate}) is deny-by-default: it
 * delegates to the Laravel authorization gate, so a record type with no matching policy is refused.
 * That default is unchanged and remains the safety seam for authenticated CRUD.
 *
 * This binding is NOT bound globally. It is constructed explicitly by a server flow whose caller has
 * already performed authorization — a form controller's own request validation, a guest-token check,
 * or any other server-side capture flow — before the payload reaches the write pipeline. In those
 * flows the gate has nothing left to decide, so it authorizes. The deny-by-default default is never
 * weakened; server flows opt into permissiveness deliberately, at the call site.
 */
class PermissiveWriteGate implements WriteGate
{
    public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
    {
        return true;
    }
}
