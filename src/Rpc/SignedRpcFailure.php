<?php

namespace Splicewire\Beam\Rpc;

use RuntimeException;
use Throwable;

/**
 * A {@see SignedRpcTransport} call that did not answer 2xx with a JSON body. The subclass is the
 * failure's class (ticket 06 Q2), so a caller maps it without reading a status:
 * {@see SignedRpcRefused}, {@see SignedRpcBindingFault}, {@see SignedRpcTransientFault},
 * {@see SignedRpcUnexpectedResponse}.
 */
abstract class SignedRpcFailure extends RuntimeException
{
    /**
     * @param  int|null  $status  the HTTP status, null when no response arrived (connect error, timeout)
     * @param  array<array-key, mixed>|null  $body  the decoded JSON response body, when it was JSON
     */
    public function __construct(
        public readonly string $name,
        public readonly string $endpoint,
        public readonly int $attempts,
        public readonly ?int $status,
        public readonly ?array $body = null,
        ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Signed RPC [%s] to %s %s after %d attempt(s)%s%s.',
            $name,
            $endpoint,
            static::describe(),
            $attempts,
            $status !== null ? " (HTTP {$status})" : '',
            $detail !== null ? ": {$detail}" : '',
        ), 0, $previous);
    }

    /** The failure class in words, for the message. */
    abstract protected static function describe(): string;
}
