<?php

namespace Splicewire\Beam\Rpc;

/**
 * A status outside the classified set (any other non-2xx), or a 2xx whose non-empty body is not a JSON object
 * or array. Never retried.
 */
class SignedRpcUnexpectedResponse extends SignedRpcFailure
{
    protected static function describe(): string
    {
        return 'answered unexpectedly';
    }
}
