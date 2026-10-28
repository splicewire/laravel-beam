<?php

namespace Splicewire\Beam\Rpc;

/**
 * The receiver answered 422: a refusal of this request (e.g. an `ArrangeRefusal`), carried in
 * {@see SignedRpcFailure::$body}. Never retried (ticket 06 Q2).
 */
final class SignedRpcRefused extends SignedRpcFailure
{
    protected static function describe(): string
    {
        return 'was refused';
    }
}
