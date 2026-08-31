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
 * Every one of the estate's 44 declaration sites predated the slot and none of them named it, so the
 * default has to be the behaviour they already had — one record, from the URL.
 *
 * ⚠️ That sentence used to read in the present tense and no longer holds: the slot has a consumer.
 * `splicewire/tower`'s `tenant-invitations.accept` resolves its `{id}` segment as a bearer TOKEN, and was
 * the first declaration anywhere to name `subject:`. The case it answers is the one the port exists for
 * and no resource-wide slot can express: `revoke`/`resend` address that resource by id while `accept`
 * addresses it by token, so `ParticleResource::$routeKey` ("one public identifier per resource, never
 * two") would have to break two verbs to serve a third. That one-off has since been generalised into
 * {@see ColumnSubject} and ships here.
 *
 * ## ⚠️ A parameterised resolver is declared as an INSTANCE, and that is not a lesser path
 *
 * The class-string row is the ergonomics every existing declaration uses, and it is genuinely better
 * when a resolver's whole configuration is its dependencies — {@see RecordSubject} takes the resource
 * registry that way. It cannot express a resolver configured by VALUE: `ColumnSubject::class` has
 * nowhere to put `'token'`. So such a resolver is spelled `new ColumnSubject('token')`, which is a
 * constant expression under PHP 8.1's new-in-initializers and therefore legal inside the `#[ParticleOp]`
 * twin as well as at `Particle::op()` — the instance row exists precisely so the two declaration sites
 * can say the same thing. A static factory could not; see the warning on `ParticleOperation::$subject`.
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
