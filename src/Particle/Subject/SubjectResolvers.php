<?php

namespace Splicewire\Beam\Particle\Subject;

use InvalidArgumentException;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * Turns whatever a declaration put in its `subject:` slot into a {@see ResolvesOperationSubject}.
 *
 * Deliberately the same normalisation shape
 * {@see BackingResolver} gives `backing:`, because it is the same
 * kind of slot — polymorphic, discriminated by TYPE rather than by a discriminator string:
 *
 * | given | meaning |
 * |---|---|
 * | a {@see ResolvesOperationSubject} instance | used as-is |
 * | a `ResolvesOperationSubject` class-string | container-resolved at REQUEST time, so it can take constructor injection |
 * | `null` | {@see RecordSubject} — the default every declaration had implicitly |
 *
 * ## Resolved at request time, never at boot
 *
 * A class-string goes through the container **per request**, exactly as a `backing:` class-string
 * does. {@see RecordSubject} takes the resource registry that way. Nothing here may resolve at boot:
 * a resolver's constructor (and whatever it injects) must not run during registration.
 *
 * ## The `null` default is what keeps the slot a pure addition
 *
 * Every one of the estate's 44 declaration sites predates the slot and none of them names it, so the
 * default has to be the behaviour they already had — one record, from the URL.
 */
class SubjectResolvers
{
    /**
     * The resolver for an operation's declared `subject:` slot.
     */
    public static function for(ParticleOperation $operation): ResolvesOperationSubject
    {
        $subject = $operation->subject;

        if ($subject instanceof ResolvesOperationSubject) {
            return $subject;
        }

        if ($subject === null) {
            return new RecordSubject;
        }

        if (is_a($subject, ResolvesOperationSubject::class, true)) {
            return app($subject);
        }

        throw new InvalidArgumentException(
            "Particle operation [{$operation->key()}] declares `subject: {$subject}`, which is not a "
            .ResolvesOperationSubject::class.'. The slot takes an instance, a class-string implementing '
            .'that port, or null for the default '.RecordSubject::class.'.'
        );
    }
}
