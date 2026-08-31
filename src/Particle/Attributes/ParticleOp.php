<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\Subject\RecordSubject;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Routing\IdConstraint;

/**
 * Marks a class as a **named particle operation** on a resource — the attribute twin of
 * `$registry->register(new ParticleOperation(...))` (ADR-0160). Boot-time discovery reflects it into a
 * runtime {@see ParticleOperation} mounted at `{$method} /{resource}/{id}/{name}` by
 * `Particle::ops()` and run by {@see ParticleOperationController}.
 *
 * ⚠️ This docblock used to say `POST …/{id}/op/{name}` *"via `Route::particleOp()`"*. Both halves are
 * dead: particle-operation-surface 12 dropped the `/op/` segment (leaving the old URL as a deprecated
 * alias) and api-surface-coherence 93 deleted the macro. The verb is no longer fixed at POST either —
 * see `method:` below.
 *
 * The op's HOST CODE — which an attribute cannot carry — lives as `public static` convention methods on
 * the SAME annotated class, wired in by {@see AttributedParticleDiscovery}:
 *
 *   - `public static function handle(\Illuminate\Database\Eloquent\Model $model, \Illuminate\Http\Request $request, mixed $actor)`  (REQUIRED)
 *       Task ⇒ returns a `ShouldQueue` job; Read/Write ⇒ a response envelope;
 *       Stream ⇒ signature adds a 4th `\Splicewire\Beam\Particle\Emitter $emit` arg and pushes framed events.
 *   - `public static function respond(\Illuminate\Database\Eloquent\Model $model): mixed`  (OPTIONAL — a Task's response projector)
 *
 * The annotated class is typically a thin invokable/op class (one op per class). This keeps the whole op —
 * declaration + handler — co-located and self-contained, so it registers with no provider glue.
 *
 * `input:`/`output:` are the op's SHAPE SLOTS — the mirror of the resource attribute's `input:`/`data:` pair
 * and the invariant's second legal declaration site (a Data class bound to an operation). Both optional; an
 * op that declares neither still registers and runs exactly as before. {@see ParticleOperation} validates
 * the pairing of `output:` with `kind:`, and its docblock carries the three states of `input:` (a class-string,
 * `false` for "accepts nothing, deliberately", `null` for undeclared) — this attribute is only the twin
 * declaration site and adds no rules of its own.
 *
 * `subject:` is the op's SUBJECT slot — what the framework resolves before `handle` runs
 * ({@see ResolvesOperationSubject}); omitted, it is {@see RecordSubject}, the `{id}` record read through
 * the resource, which is what every declaration had implicitly.
 *
 * `signed:`, `method:` and `idConstraint:` are the SCALAR slots (particle-operation-surface 14). All
 * three are plain constant expressions, all three default to the value that reproduces today's
 * behaviour, and every one of their rules lives on {@see ParticleOperation} — this twin adds none.
 *
 * `delivery:` is the WIRE slot (particle-operation-surface 11/14) — what this operation puts on the
 * wire: media types, added response headers, the applied default format, and the format enumeration
 * the controller enforces and the reference publishes. It takes the {@see DeclaresDelivery} port in the
 * same instance / class-string / `null` shape as `subject:`, and `null` is byte-identical to the
 * behaviour every declaration had before the slot existed. All of its rules — including what a
 * non-match does and why `null` is silence rather than a stop — live on {@see ParticleOperation}.
 *
 * ⚠️ **`signed:` was absent here for two days for no stated reason, and the absence was invisible.**
 * `bae7a08` added the slot to the runtime object and to one imperative op, touched five files, and
 * never opened this one or {@see AttributedParticleDiscovery} — `git log -S 'signed'` on this file
 * returned nothing at all. The result was that no attributed op *could* be signed, and the container
 * reported it as `false` rather than as an error, which is this estate's most expensive shape: a
 * declaration that exists and a forwarding that does not. When a slot is added to `ParticleOperation`,
 * the twin and `AttributedParticleDiscovery::registerOpClass()` are the same change, not a follow-up.
 *
 * ⚠️ **Spell it `RecordSubject::class` or `new ActorSubject`.** An attribute argument must be a CONSTANT
 * EXPRESSION: `new` is legal in one since PHP 8.1, a static factory call (`Subject::record()`) is not and
 * fatals at parse. With 44 declaration sites across the estate that decides the spelling once, for this
 * twin and the runtime one alike.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ParticleOp
{
    /**
     * @param  string  $resource  the particle resource key this op hangs off (route + auth)
     * @param  string  $name  the operation slug in the URL (`…/{id}/{name}`)
     * @param  OperationKind  $kind  read | write | task | stream
     * @param  class-string  $model  the FALLBACK subject class — what the `{id}` resolves to when
     *                               `$resource` names no registered particle resource. A registered
     *                               resource resolves through its own backing and gate instead —
     *                               {@see ParticleOperation}. ⚠️ This used to call that fallback *"the
     *                               live `Sharing::attachTo()` / `Resources::attachTo()` /
     *                               `market-products.*` shape"*. Booted-registry probe of all 21
     *                               `~/Herd` roots, 2026-08-31: **0 of 107 registered operations** hit
     *                               it — {@see ParticleOperation} carries the amendment
     * @param  string|false|null  $ability  the authorization token checked before the op runs
     *                                      (deny-default); `false` declares the op ungated DELIBERATELY;
     *                                      `null` is undeclared — {@see ParticleOperation}'s docblock
     *                                      carries the three states and the flip's schedule
     * @param  class-string|false|null  $abilityModel  the subject the ability is checked against; null ⇒
     *                                                 the resolved instance; a class-string ⇒ a
     *                                                 cross-model subject; `false` ⇒ no subject, the
     *                                                 entitlement plane
     * @param  class-string|false|null  $input  the Data class the op ACCEPTS — its declared payload contract;
     *                                          `false` declares it accepts nothing; `null` is undeclared
     * @param  class-string|array<string, list<class-string>>|null  $output  the Data class the op RETURNS;
     *                                                                       on {@see OperationKind::Stream} an
     *                                                                       event-name → payload-list map instead
     * @param  ResolvesOperationSubject|class-string<ResolvesOperationSubject>|null  $subject  see above
     * @param  bool  $signed  whether a validly-signed URL is an ADMITTING credential for this op —
     *                        {@see ParticleOperation}'s docblock carries the whole reasoning, including
     *                        the warning that a valid signature ADMITS and does NOT refuse an unsigned
     *                        request. Do not restate it here
     * @param  HttpMethod|null  $method  the HTTP verb this op mounts under; `null` ⇒ POST, today's
     *                                   behaviour exactly — {@see HttpMethod}
     * @param  IdConstraint|null  $idConstraint  a NARROWING override of the resource's `{id}` shape for
     *                                           this op's mount; `null` ⇒ inherit — {@see IdConstraint}
     * @param  DeclaresDelivery|class-string<DeclaresDelivery>|null  $delivery  what this op puts on the
     *                                                                          wire; `null` ⇒ undeclared, which documents and behaves
     *                                                                          exactly as before the slot existed. ⚠️ Spell it
     *                                                                          `MyDelivery::class` or `new MyDelivery` — a static
     *                                                                          factory call is not a constant expression and fatals at
     *                                                                          parse, the same trap `subject:` carries
     */
    public function __construct(
        public string $resource,
        public string $name,
        public OperationKind $kind,
        public string $model,
        public string|false|null $ability = null,
        public string|false|null $abilityModel = null,
        public string|false|null $input = null,
        public string|array|null $output = null,
        public ResolvesOperationSubject|string|null $subject = null,
        public bool $signed = false,
        public ?HttpMethod $method = null,
        public ?IdConstraint $idConstraint = null,
        public DeclaresDelivery|string|null $delivery = null,
    ) {}
}
