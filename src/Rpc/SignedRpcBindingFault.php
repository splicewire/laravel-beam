<?php

namespace Splicewire\Beam\Rpc;

/**
 * The receiver answered 401 or 403: it did not accept the signature (wrong or unrotated secret, or the wrong
 * endpoint for this binding). A fault of the binding, never retried (ticket 06 Q2).
 */
final class SignedRpcBindingFault extends SignedRpcFailure
{
    protected static function describe(): string
    {
        return 'was rejected as a binding fault';
    }
}
