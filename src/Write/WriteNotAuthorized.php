<?php

namespace Splicewire\Beam\Write;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Authorization\AccessError;
use Splicewire\Beam\Localization\CodedAuthorizationException;

/**
 * Thrown when the {@see Contracts\WriteGate} refuses a write (beam-write-pipeline ticket 03).
 *
 * It is an {@see AuthorizationException} (through {@see CodedAuthorizationException}), so the HTTP layer maps it to 403
 * with no extra wiring. The public intake route (ticket 04) relies on a deny-default refusal surfacing as a forbidden
 * response. When it is raised, NOTHING has persisted: the gate is the first stage of the pipeline, before validation
 * and before the save.
 *
 * Its message is {@see AccessError::WriteNotAuthorized} (localization 21), with the model class as `:type`, so the
 * English is unchanged; a JSON request also gets `code: write_not_authorized`.
 */
class WriteNotAuthorized extends CodedAuthorizationException
{
    public static function for(Model|string $subject): self
    {
        $type = $subject instanceof Model ? $subject::class : $subject;

        return new self(AccessError::WriteNotAuthorized, ['type' => $type]);
    }
}
