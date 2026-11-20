<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;

/**
 * A behavior was refused because Requirements remain outstanding (agreements 05). Thrown by the write pipeline's
 * {@see \Splicewire\Beam\Write\Stages\RequirementAdmissionStage}, which has no transport of its own; it carries the
 * declared {@see RequirementRefusalData} and nothing was persisted.
 *
 * The HTTP rendering is the one ADR-0247 fixes — status 428 with the refusal as the whole body — and lives in
 * {@see toResponse()}: Laravel's exception handler calls {@see render()}, and the frame handler turns it into an
 * `HttpResponseException` with the same response.
 */
class RequirementRefused extends RuntimeException
{
    public function __construct(public readonly RequirementRefusalData $refusal, public readonly string $behavior)
    {
        parent::__construct(sprintf('Behavior [%s] has outstanding requirements.', $behavior));
    }

    public function toResponse(): JsonResponse
    {
        return response()->json($this->refusal->toArray(), 428);
    }

    public function render(): JsonResponse
    {
        return $this->toResponse();
    }
}
