<?php

namespace Splicewire\Beam\Rpc;

/**
 * A connect error, a timeout, a 5xx or a 429 that outlasted the transport's retries. `$status` is null when no
 * response arrived; `$attempts` counts every request made (ticket 06 Q2).
 */
final class SignedRpcTransientFault extends SignedRpcFailure
{
    protected static function describe(): string
    {
        return 'failed transiently';
    }
}
