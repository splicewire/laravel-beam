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

    /**
     * Several fields refused at once (localization 21): each field with its coded error and that error's parameters.
     *
     *   throw CodedValidationException::many(['email' => [ParticleRequestError::ServerManagedField, []], …]);
     *
     * @param  array<string, array{0: CodedError, 1?: array<string, scalar>}>  $refusals
     */
    public static function many(array $refusals): static
    {
        $messages = [];
        $codes = [];
        foreach ($refusals as $field => $refusal) {
            [$error, $params] = [$refusal[0], $refusal[1] ?? []];
            $messages[$field] = $error->message($params);
            $codes[$field] = [$error->code()];
        }

        $exception = static::withMessages($messages);
        $exception->codes = $codes;

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
