<?php

namespace Splicewire\Beam\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Localization\Data\CodedValidationErrorData;

/**
 * A validation refusal whose messages are coded errors (localization ticket 13). It IS a {@see ValidationException},
 * so every caller and handler that catches one keeps working and a redirecting (non-JSON) request is answered as
 * before; a request that expects JSON gets {@see CodedValidationErrorData}: Laravel's `{message, errors}` plus `codes`.
 *
 *   throw CodedValidationException::for('events', HookSubscriptionError::EventsRequired);
 */
class CodedValidationException extends ValidationException
{
    /** @var array<string, list<string>> */
    protected array $codes = [];

    /**
     * @param  array<string, scalar>  $params
     */
    public static function for(string $field, CodedError $error, array $params = []): static
    {
        $exception = static::withMessages([$field => $error->message($params)]);
        $exception->codes = [$field => [$error->code()]];

        return $exception;
    }

    /** @return array<string, list<string>> */
    public function codes(): array
    {
        return $this->codes;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse((new CodedValidationErrorData(
            message: $this->getMessage(),
            errors: $this->errors(),
            codes: $this->codes,
        ))->toArray(), $this->status);
    }
}
