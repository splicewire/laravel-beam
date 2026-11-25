<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementRefused;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\WriteContext;

/**
 * The requirement stage of the write chain (agreements 05, ADR-0247 amendment), placed RIGHT AFTER
 * {@see AuthorizeStage} (and after a backing's own ownership stage): a caller the write gate refuses gets 403 and never
 * learns the Requirements; an admitted caller with outstanding Requirements is refused before validation, so the client
 * receives the actionable Agreement list rather than a validation error. Nothing persists on a refusal.
 *
 * A write that carries no {@see \Splicewire\Beam\Write\WriteRequirement} passes through without asking, so every write
 * outside a resource transport is unchanged.
 *
 * @throws RequirementRefused Requirements remain outstanding (nothing persisted)
 */
class RequirementAdmissionStage implements WriteStage
{
    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        $requirement = $context->requirement;

        if ($requirement !== null) {
            $refusal = app(RequirementAdmission::class)->inspect(
                $context->actor,
                $requirement->behavior,
                $requirement->subject,
                $requirement->submittedMembers,
                $requirement->input,
            );

            if ($refusal !== null) {
                throw new RequirementRefused($refusal, $requirement->behavior);
            }
        }

        return $next($context);
    }
}
