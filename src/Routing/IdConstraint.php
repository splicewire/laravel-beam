<?php

namespace Splicewire\Beam\Routing;

use Splicewire\Beam\Doctor\ParticleIdConstraintKeyTypeAudit;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

/**
 * What shape a particle resource's `{id}` route parameter is — the enum behind
 * {@see ParticleResource::$idConstraint} and its narrowing twin on {@see ParticleOp}
 * (particle-operation-surface tickets 11 and 14).
 *
 * ## Only `Uuid` emits a route constraint today, and the other two are DELIBERATELY inert
 *
 * `ParticleMounter` has exactly one enforcement spelling — `$route->whereUuid('id')` — and it is
 * reached from `$idConstraint === 'uuid'` at five separate places in that file. `Ulid` and `Int` are
 * therefore **declared-but-unenforced**: they record what the author knows, and they feed
 * {@see ParticleIdConstraintKeyTypeAudit}, which is the instrument that decides whether enforcing them
 * would be safe. Ticket 14 gate 3 is explicit that the flip is *one line, once that audit reads zero*,
 * and is not part of the landing that introduces the enum.
 *
 * The reason for the caution is measured rather than theoretical: `~/Herd/audiostud` declared
 * `'idConstraint' => 'int'` on `operator-customers`, whose model is `HasUuids`, with a provider
 * docblock asserting the opposite. Turning `Int` into `->whereNumber('id')` on the day the enum shipped
 * would have 404'd that host's entire operator-customer surface behind a green suite.
 *
 * ## `None` is not the same declaration as saying nothing
 *
 * `null` on either slot is *undeclared* — nothing is enforced and the audit has nothing to check.
 * `None` is *declared unconstrained*, which is the honest reading of a route that must swallow a
 * non-key path segment. `~/Herd/splicewire-app/routes/tenant.php` already spells this by hand
 * (`idConstraint: 'none'` on the circuits rendering, with a comment explaining why); the enum gives
 * that sentence a type instead of a string literal.
 *
 * ## Why `Int` rather than `Integer`
 *
 * The string backing is the wire spelling the estate's mount sites already use (`'uuid'`, `'int'`), so
 * `IdConstraint::from($options['idConstraint'])` reads existing call sites without a translation table
 * during the migration. `Int` is a legal case name — it is `int` the *type* that is reserved, not the
 * identifier — and matching the backing value keeps the two halves from drifting.
 */
enum IdConstraint: string
{
    case Uuid = 'uuid';
    case Ulid = 'ulid';
    case Int = 'int';
    case None = 'none';

    /**
     * Whether this constraint is one {@see ParticleMounter} actually enforces on the route. Exactly one
     * is, today; see the class docblock for why the other two are inert rather than missing.
     */
    public function enforced(): bool
    {
        return $this === self::Uuid;
    }
}
