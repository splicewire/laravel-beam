<?php

namespace Splicewire\Beam\Particle\Subject;

use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * The SUBJECT port — what a {@see ParticleOperation} resolves before its host `handle` closure runs.
 *
 * ## Subject means the CONTEXT, not the return
 *
 * *What does this operation resolve before it runs?* — not *what does it hand back?* The
 * return-reading breaks on the first two verbs: a create resolves nothing and returns one record; a
 * nested list resolves one record and returns many, of a DIFFERENT resource.
 *
 * ## A polymorphic slot, not an enum
 *
 * Four implementations ship — {@see RecordSubject} (one record, from the URL), {@see ColumnSubject}
 * (one record, from the URL read as a named column), {@see ActorSubject} (the acting principal),
 * {@see NoSubject} (none, a collection-level operation) — and the estate already carries cases outside
 * any small enum: a `{type}/{id}` polymorphic transition, 21 nested `{id}/…/{id}` mounts, and
 * `particleRelative`'s `Closure $via`. So the discriminator is the TYPE, exactly as
 * `ParticleResource::$backing` discriminates by type rather than by a `sourceKind` string nothing ever
 * branched on.
 *
 * ✅ **That argument stopped being hypothetical, and then it round-tripped.** `splicewire/tower` wrote
 * the first implementation of this port outside beam — an invitation resolved from its bearer TOKEN,
 * for an operation whose sibling verbs on the same resource resolve by id. Nothing about it was
 * tower-specific once the model and the column were pulled out of its body, so it came back here as
 * {@see ColumnSubject}. The polymorphic slot is what let a host answer a case beam had not enumerated
 * *and* what let the answer be adopted without a new slot — measured rather than predicted, in both
 * directions.
 *
 * ## ⚠️ It takes parameters and an actor, and NEVER a `Request`
 *
 * This is the whole reason the port has this signature and not a convenient one.
 * {@see AbilityResolver} refuses to read ambient authentication
 * because MCP over stdio has no HTTP request and no ambient user — the actor arrives as an argument.
 * A subject resolver that took a `Request` would re-couple precisely what that decoupling bought,
 * and would make an operation resolvable over HTTP and unresolvable over every other transport.
 *
 * A transport therefore projects whatever it has into two neutral arguments: the route/tool
 * parameters as a plain array, and the actor from its {@see ActorPort}.
 */
interface ResolvesOperationSubject
{
    /**
     * The route parameters this resolver CONSUMES, in mount order.
     *
     * Declarative rather than inspected, so a mount, the published reference and the client codegen
     * can all know an operation's URL shape without booting a request.
     *
     * @return list<string>
     */
    public function pathParameters(): array;

    /**
     * Whether this resolver yields a subject at all — `false` marks a COLLECTION-level operation.
     *
     * ⚠️ Not derivable from `pathParameters() === []`: {@see ActorSubject} consumes no path parameter
     * and does yield a subject. It is also what tells the authorization plane, at declaration time,
     * whether there is anything for a subject-bearing ability to be checked against.
     */
    public function yieldsSubject(): bool;

    /**
     * Resolve the operation's subject, or null when it yields none.
     *
     * @param  array<string, mixed>  $parameters  the transport's parameter bag, keyed by name
     * @param  mixed  $actor  the acting principal, from the transport's `ActorPort` — null is legitimate
     */
    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object;
}
