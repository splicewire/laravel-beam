<?php

namespace Splicewire\Beam\Localization;

use Illuminate\Auth\Access\AuthorizationException;
use Splicewire\Beam\Localization\Concerns\RendersCodedError;
use Throwable;

/**
 * An authorization refusal whose message is a coded error (localization 21; coord-lead ruling 23:18Z).
 *
 * - It IS an {@see AuthorizationException}, so every `catch (AuthorizationException)` keeps working and the framework
 *   still answers 403 (or the status set with `withStatus()`).
 * - A request that expects JSON gets `{message}` plus the stable `code`.
 *
 *   throw new CodedAuthorizationException(AccessError::WorkspaceUninitialized);
 */
class CodedAuthorizationException extends AuthorizationException
{
    use RendersCodedError;

    /** @param  array<string, scalar>  $params */
    public function __construct(CodedError $error, array $params = [], ?Throwable $previous = null)
    {
        $this->error = $error;
        $this->params = $params;
        parent::__construct($error->message($params), null, $previous);
    }

    /** The status the framework renders this refusal with: 403 unless one was set. */
    public function getStatusCode(): int
    {
        return $this->status() ?? 403;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
