<?php

declare(strict_types=1);

namespace Splicewire\Beam\Write;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Thrown when the {@see Contracts\WriteGate} refuses a write (beam-write-pipeline ticket 03).
 *
 * Extends {@see AuthorizationException} so the HTTP layer maps it to 403 with no extra wiring — the
 * public intake route (ticket 04) relies on a deny-default refusal surfacing as a forbidden response.
 * When it is raised, NOTHING has persisted: the gate is the first stage of the pipeline, before
 * validation and before the save.
 */
final class WriteNotAuthorized extends AuthorizationException
{
    public static function for(Model|string $subject): self
    {
        $type = $subject instanceof Model ? $subject::class : $subject;

        return new self("The write gate refused a write to [{$type}].");
    }
}
