<?php

namespace Splicewire\Beam\Localization;

use Splicewire\Beam\Localization\Concerns\RendersCodedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * An HTTP refusal other than a 404 (a 405, …) whose message is a coded error (localization 21). It IS an
 * {@see HttpException} with the same status as before; a request that expects JSON gets `{message}` plus the `code`.
 * A 404 is {@see CodedNotFoundHttpException}, so `catch (NotFoundHttpException)` keeps working.
 *
 *   throw new CodedHttpException(405, ParticleRequestError::ActionNotSupported, ['action' => $action, 'resource' => $key]);
 */
class CodedHttpException extends HttpException
{
    use RendersCodedError;

    /**
     * @param  array<string, scalar>  $params
     * @param  array<string, string>  $headers
     */
    public function __construct(int $statusCode, CodedError $error, array $params = [], ?Throwable $previous = null, array $headers = [])
    {
        $this->error = $error;
        $this->params = $params;
        parent::__construct($statusCode, $error->message($params), $previous, $headers);
    }
}
