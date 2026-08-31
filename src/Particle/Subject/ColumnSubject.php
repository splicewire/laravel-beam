<?php

namespace Splicewire\Beam\Particle\Subject;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * One record, resolved from the URL's `{id}` read as a NAMED COLUMN — a token, a slug, an external ref.
 *
 * ## Why it exists beside {@see RecordSubject} rather than as a resource slot
 *
 * `ParticleResource::$routeKey` already says "resolve `{id}` against THIS column", and its own docblock
 * rules itself out for this job: *one public identifier per resource, never two*. It is resource-WIDE,
 * so a surface whose verbs disagree cannot use it. The measured instance is `splicewire/tower`'s
 * invitation surface — `revoke` and `resend` address an invitation by primary key while `accept`
 * addresses it by its bearer token — where declaring `routeKey: 'token'` would have to break two verbs
 * to serve a third.
 *
 * The subject slot is per-OPERATION, which is the grain that disagreement actually has. That is the
 * whole argument for this class: not that resolving by a column is new (the read path has done it since
 * `routeKey` shipped), but that the DECLARATION had nowhere to sit at operation grain.
 *
 * ## It is parameterised by construction, so it is declared as an INSTANCE
 *
 * `subject: new ColumnSubject('token')`. {@see SubjectResolvers} also accepts a class-string, and a
 * class-string is what every other resolver in the estate uses — but a class-string cannot carry a
 * column name, and there is no defensible default column to fall back on. `new` in an attribute
 * argument is a constant expression under PHP 8.1's new-in-initializers, so the instance spelling works
 * at BOTH declaration sites (`#[ParticleOp]` and `Particle::op()`), which a static factory
 * (`Subject::column('token')`) would not — the same trap {@see ParticleOperation}'s `$subject` docblock
 * already warns about for `delivery:`.
 *
 * Constructing it with no column throws rather than querying `where('token', '')`-shaped nonsense, so
 * the class-string path fails loudly with the spelling it wanted instead of resolving to a resolver
 * that matches nothing.
 *
 * ## The `{id}` segment does not rename
 *
 * {@see pathParameters()} stays `['id']`. This resolver changes what the id MEANS, not where it comes
 * from, so no mount, published reference or client codegen has to learn a second parameter name. What
 * it usually DOES want alongside is `idConstraint: IdConstraint::None` on the operation, since a token
 * will not satisfy a `uuid`-shaped resource constraint.
 *
 * ## ⚠️ `throughResource` — an opt-out, because the resource's gate is not always answerable
 *
 * By default the lookup runs through the resource's backing and {@see ResourceRecordLookup}, so the
 * resource's `scope` (ADR-0156 §83's row-level read gate) and `includes` apply exactly as they do for
 * {@see RecordSubject}; only `routeKey` is overridden, by the column declared here. That is the safe
 * default and it should stay the answer for nearly every resource.
 *
 * `throughResource: false` resolves against the bare model instead. It exists for the measured case
 * where the resource's scope CANNOT be satisfied at the mount: tower's invitation `accept` is a central
 * route with no tenancy middleware, so the resource's `tenant_id` scope — correct on every other verb —
 * would 404 every legitimate accept. The opt-out is deliberately a declaration rather than a silent
 * fallback, because "this operation resolves past the resource's row gate" is a sentence whose author
 * should have to write it down.
 *
 * ⚠️ **Which model that is no longer comes from the operation.** This used to read *"resolves against
 * {@see ParticleOperation::$model}"*, and it was the one path that made that deprecated slot look
 * unavoidable — the opt-out is about skipping the resource's SCOPE, not about disowning the resource.
 * So it now reads the model off the same backing, through
 * {@see OperationSubjectModel}, and skips only the gate. The opt-out keeps its exact
 * meaning and stops being a reason to declare `model:`.
 *
 * ## ⚠️ It takes NO extra predicate, and that is a decision
 *
 * The one-off this generalises also carried `whereNull('accepted_at')`. That does not move here. A
 * subject resolver that silently filters is a gate wearing a lookup's clothing: a row excluded by a
 * lookup is indistinguishable from a row that does not exist, so an already-accepted invitation answers
 * *404 no such token* rather than *409 already accepted* — and the estate has already been bitten by
 * "has a scope" not meaning "is row-gated".
 *
 * There are two places that predicate belongs, and this is neither: the resource's own `scope`, when
 * the condition is a read gate true for every verb, or the operation's `handle`/`ability`, when it is a
 * precondition of THAT verb — which is what a one-shot token is. A single-use guard is a state machine,
 * not an identifier.
 */
class ColumnSubject implements ResolvesOperationSubject
{
    /**
     * @param  string  $column  the column the `{id}` segment is matched against
     * @param  bool  $throughResource  whether to resolve through the registered resource's backing and
     *                                 its declared `scope`/`includes`; `false` resolves against the bare
     *                                 model (still read from the resource's backing), past the row gate
     */
    public function __construct(
        public string $column = '',
        public bool $throughResource = true,
        protected ?ParticleResourceRegistry $resources = null,
        protected ?ResourceRecordLookup $lookup = null,
        protected ?OperationSubjectModel $models = null,
    ) {
        if ($this->column === '') {
            throw new InvalidArgumentException(
                self::class.' needs the column its `{id}` resolves against, and a class-string cannot '
                ."carry one. Declare it as an instance — `subject: new ColumnSubject('token')`."
            );
        }
    }

    /**
     * The segment is still `{id}` — see the class docblock.
     *
     * @return list<string>
     */
    public function pathParameters(): array
    {
        return ['id'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        $id = $parameters['id'] ?? null;

        $id = $id instanceof Model ? (string) $id->getKey() : (string) $id;

        if ($this->throughResource && ($resource = $this->registeredResource($operation)) !== null) {
            $backing = $resource->backing();

            if ($backing instanceof QueriesRecords) {
                return ($this->lookup ?? new ResourceRecordLookup)
                    ->within($resource, $backing->query([]), $id, $this->column);
            }
        }

        $model = ($this->models ?? new OperationSubjectModel($this->resources))->require($operation);

        return $model::query()->where($this->column, $id)->firstOrFail();
    }

    /**
     * The registry is consulted NON-throwingly: an operation registered against a key that is not a
     * registered particle resource is an ordinary declaration, not an error.
     *
     * ⚠️ This used to end *"— the same 13+ live sites {@see RecordSubject} documents."* That figure was
     * a count of declaration sites in source quoted as live registrations; a booted-registry probe of
     * all 21 `~/Herd` roots on 2026-08-31 puts the live population at **0 of 107** registered
     * operations. The non-throwing read stays — it is right on its own terms — but nothing in the
     * estate exercises the branch it guards.
     */
    protected function registeredResource(ParticleOperation $operation): ?ParticleResource
    {
        $resources = $this->resources ?? app(ParticleResourceRegistry::class);

        return $resources->has($operation->resource) ? $resources->get($operation->resource) : null;
    }
}
