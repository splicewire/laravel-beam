<?php

namespace Splicewire\Beam\Write\Dedupe;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Write\PayloadRejected;
use Splicewire\Beam\Write\WriteNotAuthorized;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown under `mode: reject` when a capture's key matches an earlier one (beam-facade ticket 50
 * §5). When it is raised, NOTHING has persisted: dedupe is the third pipeline stage, after
 * authorization and validation and before the save.
 *
 * Extends {@see ConflictHttpException} so the HTTP layer maps it to 409 with no extra wiring — the
 * same trick {@see WriteNotAuthorized} uses to reach 403. A host assembling its own body reaches for
 * {@see ResponseBody::conflict()}.
 *
 * Deliberately NOT 422 and not {@see PayloadRejected}'s shape: the payload
 * CONFORMS. It is the ledger's state that refuses, and saying otherwise is the same class of lie
 * ticket 51 spent its session fixing in the acceptance gate.
 *
 * ## `reject` is an existence oracle by construction
 *
 * The 409 IS the disclosure — an anonymous caller learns from the status code alone whether an
 * address is already on the list. That is not a defect in this class, it is what the mode means, so
 * declaring it on a PUBLIC intake schema is a mistake. Ruled legitimate only behind an
 * authenticated or non-public door (ticket 50 §6); `ignore` is the mode a public door wants, and it
 * is byte-identical to a fresh `admit` for exactly this reason.
 */
class DuplicateRejected extends ConflictHttpException
{
    public static function for(Model|string $subject): self
    {
        $type = $subject instanceof Model ? $subject::class : $subject;

        return new self("A capture matching an earlier one was refused for [{$type}].");
    }
}
