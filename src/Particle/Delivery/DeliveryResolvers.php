<?php

namespace Splicewire\Beam\Particle\Delivery;

use InvalidArgumentException;
use Splicewire\Beam\Doctor\ParticleIdConstraintKeyTypeAudit;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Scribe\OpenApi\DeliveryGenerator;

/**
 * Turns whatever a declaration put in its `delivery:` slot into a {@see DeclaresDelivery}, or `null`.
 *
 * Deliberately the same normalisation shape {@see SubjectResolvers} gives `subject:` and
 * {@see BackingResolver} gives `backing:`, because it is the same kind
 * of slot — polymorphic, discriminated by TYPE rather than by a discriminator string:
 *
 * | given | meaning |
 * |---|---|
 * | a {@see DeclaresDelivery} instance | used as-is |
 * | a `DeclaresDelivery` class-string | container-resolved at REQUEST time, so it can take constructor injection |
 * | `null` | the operation has not stated what it puts on the wire |
 *
 * ## What `null` means, and why it is SILENCE rather than a stop
 *
 * `null` is the state every one of the estate's declarations was in before this slot existed, so it has
 * to reproduce today's behaviour exactly: no `?format` parameter, no format validation, no 422, and a
 * 200 documented from `output:` (or as an untyped object) exactly as before. Nothing this slot adds can
 * reach an operation that does not name it — the widening is one-directional by construction, which is
 * the shape `beam-facade` 184 settled after a map entry nearly converted a quiet advisory into a
 * stopped install.
 *
 * ## What a NON-MATCH means, and why THAT one throws
 *
 * A `delivery:` that names a class which does not implement the port is **grammar the declaration's
 * author could have gotten right without knowing which host would load it** — this file's estate rule
 * for what may be fatal. It is a typo or a wrong import, never a fact about the host, so it throws with
 * the offending class named. Contrast {@see ParticleIdConstraintKeyTypeAudit},
 * which is advisory precisely because its answer depends on the host's model.
 *
 * ## Resolved at request time, never at boot
 *
 * A class-string goes through the container **per request**, exactly as a `subject:` or `backing:`
 * class-string does. Nothing here may resolve at boot: a delivery's constructor (and whatever it
 * injects) must not run during registration, and a Scribe strategy calling this at generation time is
 * not a request either — both are outside the container-warm window a boot-time resolve would assume.
 */
class DeliveryResolvers
{
    /**
     * The delivery contract for an operation's declared `delivery:` slot, or null when it declares none.
     */
    public static function for(ParticleOperation $operation): ?DeclaresDelivery
    {
        $delivery = $operation->delivery;

        if ($delivery instanceof DeclaresDelivery) {
            return $delivery;
        }

        if ($delivery === null) {
            return null;
        }

        if (is_a($delivery, DeclaresDelivery::class, true)) {
            return app($delivery);
        }

        throw new InvalidArgumentException(
            "Particle operation [{$operation->key()}] declares `delivery: {$delivery}`, which is not a "
            .DeclaresDelivery::class.'. The slot takes an instance, a class-string implementing that '
            .'port, or null for "this operation has not said what it puts on the wire".'
        );
    }

    /**
     * What the operation says it puts on the wire, with the not-declared case spelled out rather than
     * guessed — written as the operation-surface twin of the rendering surface's `ReadsRenderingStamp`,
     * in deliberately the same array shape because one document-assembly hook consumed both. 13 deleted
     * the other one; the shape is kept because {@see DeliveryGenerator}
     * is written against it.
     *
     * A declared delivery with an EMPTY media-type list documents the WILDCARD media type — "delivers
     * something, has not said what" — rather than having `application/json` invented for it because
     * most things are JSON.
     *
     * @return array{mediaTypes: list<string>, headers: array<string, string>, default: ?string, formats: list<string>}|null
     */
    public static function contract(ParticleOperation $operation): ?array
    {
        $delivery = static::for($operation);

        if ($delivery === null) {
            return null;
        }

        $types = array_values(array_unique($delivery->mediaTypes()));

        return [
            'mediaTypes' => $types === [] ? ['*/*'] : $types,
            'headers' => $delivery->deliveryHeaders(),
            'default' => $delivery->defaultFormat(),
            'formats' => array_values($delivery->formats()),
        ];
    }
}
