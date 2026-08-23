<?php

namespace Splicewire\Beam\Particle\Backing;

/**
 * What BACKS a particle resource — the one polymorphic slot that replaced the
 * `model` / `source` / `sourceKind` triple (particle-contribution-seam ticket 11).
 *
 * ## Why this port has no methods
 *
 * `ResourceBacking` is a **marker**: every real job a backing does is a CAPABILITY declared by a
 * sub-interface ({@see QueriesRecords}, {@see WritesRecords}, {@see ResolvesRecord},
 * {@see BacksModel}). That is deliberate, and it is the whole reason the axis count came out at zero.
 *
 * The declaration used to carry three fields describing its backing — `sourceKind` ('model'|'service'),
 * `model` (an Eloquent class-string) and `source` (a `UnionSource` class-string) — bound by a `model`
 * XOR `source` contract. Ticket 06 found the XOR had no slot for "both", ticket 11 found the problem
 * was not a missing third state but the wrong number of AXES: `sourceKind` had zero branching readers
 * estate-wide, and `model` was four different jobs wearing one name. Polymorphism is the discriminator
 * a string field was standing in for, so all three fields go — they are not re-homed.
 *
 * A backing is named per-resource and **container-resolved at request time**, never eagerly at boot, so
 * it can take constructor injection (a tenant connection, the sub-source repos). That per-resource
 * granularity is itself load-bearing: `Splicewire\Beam\Read\Contracts\ParticleHydrator` is a single
 * HOST-WIDE binding trying to serve every resource, which is why its federated arm — discriminating by
 * schema name against a field that always holds a class-string — could never hit. A resource that names
 * its own backing removes the discrimination step entirely.
 *
 * ## Capability is the CEILING; the affordance flags narrow it
 *
 * `instanceof WritesRecords` is what the backing **can** do. `creatable`/`editable`/`deletable`/
 * `showable` on the declaration are what this resource **may** do, and they can only narrow. An
 * affordance set true against a backing lacking the capability is a declaration error and
 * {@see assertAffordancesWithinCapability()} throws at registration — the shape
 * `ParticleOperation::assertOutputMatchesKind()` already ships.
 *
 * That is what lets `tenants` read honestly for the first time: a backing that COULD write, declared
 * closed — rather than `sourceKind: 'service'` plus `creatable: false` saying it twice in two
 * vocabularies.
 *
 * ## Implementing
 *
 * Implement the capabilities the backing genuinely has, and no others. The common case — one Eloquent
 * model, queried and written the ordinary way — is already shipped as {@see EloquentBacking}; a
 * resource does not hand-roll a backing to say "I am a model".
 *
 * @see EloquentBacking  the model-backed default, carrying all four capabilities
 */
interface ResourceBacking {}
