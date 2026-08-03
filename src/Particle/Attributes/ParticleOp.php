<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;
use Splicewire\Beam\Particle\OperationKind;

/**
 * Marks a class as a **named particle operation** on a resource — the attribute twin of
 * `$registry->register(new ParticleOperation(...))` (ADR-0160). Boot-time discovery reflects it into a
 * runtime {@see \Splicewire\Beam\Particle\ParticleOperation} mounted at
 * `POST /{resource}/{id}/op/{name}` (via `Route::particleOp()`) and run by
 * {@see \Splicewire\Beam\Http\Particle\ParticleOperationController}.
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
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ParticleOp
{
    /**
     * @param  string  $resource  the particle resource key this op hangs off (route + auth)
     * @param  string  $name  the operation slug in the URL (`…/op/{name}`)
     * @param  OperationKind  $kind  read | write | task | stream
     * @param  class-string  $model  the model the `{id}` resolves to
     * @param  string|null  $ability  an authorization ability checked before the op runs (deny-default)
     * @param  class-string|null  $abilityModel  the model the ability is checked against; null ⇒ the resolved instance
     */
    public function __construct(
        public string $resource,
        public string $name,
        public OperationKind $kind,
        public string $model,
        public ?string $ability = null,
        public ?string $abilityModel = null,
    ) {}
}
