<?php

namespace Splicewire\Beam\Localization\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Localization\CodedError;

/**
 * An HTTP exception carrying a coded error (localization 21): a request that expects JSON gets Laravel's `{message}` plus
 * the stable `code` beside it; any other request renders as before (null hands it back to the framework's handler).
 */
trait RendersCodedError
{
    private CodedError $error;

    /** @var array<string, scalar> */
    private array $params = [];

    /** The stable wire code (the case's value). */
    public function errorCode(): string
    {
        return $this->error->code();
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        // Resolved from the coded error at render time, in the request's locale; never a forwarded raw message.
        return new JsonResponse(['message' => $this->error->message($this->params), 'code' => $this->error->code()], $this->getStatusCode(), $this->getHeaders());
    }
}
