<?php

namespace Splicewire\Beam\Localization;

use Splicewire\Beam\Localization\Concerns\RendersCodedError;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A 404 whose message is a coded error (localization 21; ticket 13's pattern beyond 422). It IS a
 * {@see NotFoundHttpException}, so every `catch (NotFoundHttpException)` and handler keeps working and a non-JSON request
 * renders as before; a request that expects JSON gets Laravel's `{message}` plus the stable `code` beside it.
 *
 *   throw new CodedNotFoundHttpException(CollectionError::SchemaHasNoCollection);
 */
class CodedNotFoundHttpException extends NotFoundHttpException
{
    use RendersCodedError;

    /**
     * @param  array<string, scalar>  $params
     * @param  array<string, string>  $headers
     */
    public function __construct(CodedError $error, array $params = [], ?Throwable $previous = null, array $headers = [])
    {
        $this->error = $error;
        $this->params = $params;
        parent::__construct($error->message($params), $previous, 0, $headers);
    }
}
