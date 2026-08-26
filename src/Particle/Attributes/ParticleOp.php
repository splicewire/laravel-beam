<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\Subject\RecordSubject;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;

/**
 * Marks a class as a **named particle operation** on a resource — the attribute twin of
 * `$registry->register(new ParticleOperation(...))` (ADR-0160). Boot-time discovery reflects it into a
 * runtime {@see ParticleOperation} mounted at
 * `POST /{resource}/{id}/op/{name}` (via `Route::particleOp()`) and run by
 * {@see ParticleOperationController}.
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
     * @param  string  $name  the operation slug in the URL (`…/op/{name}`)
     * @param  OperationKind  $kind  read | write | task | stream
     * @param  class-string  $model  the FALLBACK subject class — what the `{id}` resolves to when
     *                               `$resource` names no registered particle resource (the live
     *                               `Sharing::attachTo()` / `Resources::attachTo()` /
     *                               `market-products.*` shape). A registered resource resolves
     *                               through its own backing and gate instead — {@see ParticleOperation}
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
    ) {}
}
