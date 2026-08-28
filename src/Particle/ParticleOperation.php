<?php

namespace Splicewire\Beam\Particle;

use Closure;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Doctor\UngatedOperationAudit;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Subject\RecordSubject;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;

/**
 * A named operation on a particle resource, mounted at `POST /{resource}/{id}/op/{name}` by
 * `Route::particleOp()` and run by {@see ParticleOperationController}.
 *
 * This generalizes the CRUD verbs to arbitrary named actions — the escape hatch that lets the bespoke
 * (bucket-D) controllers dissolve their *actions* while the framework supplies the cross-cutting plumbing
 * (routing, params validation, authorization, and — for a {@see OperationKind::Task} — the sync/async
 * convention). The `handle` closure is HOST-WRITTEN and may do anything a controller method does (it IS,
 * in effect, a controller method that the framework calls) — so a Laravel dev keeps writing ordinary code
 * and simply returns a job for a Task or a response envelope for a read/write.
 *
 * The {@see OperationKind} is what avoids over-engineering: read/write ops are plain sync calls; only a
 * Task rides the queue. A Task's `handle` returns a `ShouldQueue` job (the framework dispatches it sync or
 * async per `?async`) rather than doing the work inline, so the SAME operation can run either way with no
 * host branching — the `?async` dance controllers copy today (FragmentUrlBatch.run, Composition.*)
 * collapses to a convention. A Stream's `handle` instead receives an {@see Emitter} 4th arg and pushes
 * framed events down a held connection; the framework owns the `StreamedResponse` (ADR-0160).
 *
 * `input`/`output` are the op's DECLARED SHAPES — the invariant's second legal declaration site, mirroring the
 * `input`/`data` pair a {@see ParticleResource} already carries. Both optional, so an op that declares neither
 * behaves exactly as it did before the slots existed.
 *
 * `input` is THREE-STATE, and the third state is what makes the second auditable (api-surface-coherence
 * ticket 30):
 *
 *   - a **class-string** — the op accepts this payload, validated before `handle` runs and published as the
 *     endpoint's contract;
 *   - **`false`** — the op accepts NOTHING, deliberately. Enforced: a request carrying input on the op's
 *     axis is rejected rather than silently ignored;
 *   - **`null`** — UNDECLARED, which today means "accept anything, validate nothing". This is the residue,
 *     not a design: it is the state an op is in because nobody has looked at it yet.
 *
 * The axis `input` describes is the ROUTE's, not the declaration's: `Route::particleOp()` chooses the HTTP
 * method, so the same declared class publishes as a request body on a write op and as query parameters on a
 * GET one. A declaration says WHAT is accepted; the mount says where it arrives.
 *
 * **`null` is scheduled to become a synonym for `false`** — a contract that is only binding when present is
 * not a contract. That flip is deliberately NOT made here. **The paragraph that used to stand in this slot
 * described a plan that changed and is corrected by api-surface-coherence 69 (2026-08-28):**
 *
 *   - Its figure is dead. It read *"zero of the estate's registered operations declare `input` at all"*.
 *     Live: **25 operations — 5 class-string, 19 `false`, 1 `null`.** Twenty-four of twenty-five declare.
 *   - Its coupling is dead. It promised the flip *"covers the resource axis in the same act"*. The two axes
 *     are **decoupled**, and in the opposite direction 65 predicted: the resource axis was the one that
 *     could not even SPELL `false` until 69 widened {@see ParticleResource::$input}, and it is the axis
 *     that carried a live forgery (`seller-repo-authorizations`). The op axis is the one that cannot flip.
 *   - Its gate is dead as written. *"The count of remaining `null`s reaching zero"* is **unreachable by
 *     construction**: `media.ingest`'s `null` is not undone work — beam-media owns the operation and the
 *     HOST owns what its request means, so any class named there would be a lie in another host. See
 *     `IngestMedia`'s own fourteen-line refusal.
 *
 * **What replaces it**, on {@see UngatedOperationAudit}'s precedent — the sibling
 * slot that asked this same question first, measured a constructor throw, and refused it because it would
 * fail BOOT: `input:`'s residue becomes a **counted, warn-level audit over both particle registries**, not
 * a reject. **That audit is {@see \Splicewire\Beam\Doctor\UndeclaredInputAudit}, built by
 * api-surface-coherence 117, and it IS the gate on this flip** — the paragraph you are reading is no
 * longer the gate, so the count is a check rather than a memory. It emits two checks, not one, because
 * the axes are decoupled: `particle.resource-input` counts REACHABLE write mounts derived from the router
 * per run, `particle.operation-input` counts this registry. The estate's own answer to *"what does
 * undeclared mean"* on the `ability:` axis was never *"rejected"*; it was *"COUNTED, loudly, until it is
 * zero."* The NAMED carve-out 69 promised is spelled in that audit's
 * `ACKNOWLEDGED` map — a key plus its reason, reported as acknowledged rather than outstanding, so no
 * fourth declaration state enters this attribute for the sake of one operation. `media.ingest` is its
 * one entry. Blast radius the audit exists to make visible, measured statically 2026-08-28: five
 * `#[ParticleOp]` declarations outside this host carry no `input:` (`laravel-beam-calendars` ×3,
 * `laravel-beam-rank` ×1, `laravel-satellite-training` ×1) — none with a deferral comment, so all five
 * read as omissions rather than decisions, which is the state `false` exists to distinguish.
 *
 * `output` is kind-dependent, and that asymmetry is deliberate rather than an inconsistency: a read/write/task
 * resolves ONE payload, so a single class-string says everything; a Stream emits a sequence of discrete typed
 * events under distinct wire names, so it takes `[eventName => [DataClass, ...]]` — the shape the `->streams()`
 * route macro already proved. One event name may cover several payload variants discriminated by a DTO field,
 * so the value is a LIST, not a single class. Collapsing a stream to one class would lose the event-name
 * dimension a client needs to narrow each listener.
 *
 * ## The key IS the registry key and the permission name — one string, three jobs
 *
 * An operation SELF-KEYS ({@see HasRegistryKey}) as `{$resource}.{$name}`, and
 * {@see ParticleOperationRegistry} stamps its declared root (`beam.particle.operations`) onto it. The
 * root is therefore never spelled here, which is what makes a rekey a one-line attribute change on the
 * registry rather than an edit at every declaration site.
 *
 * The separator moved from `:` to `.` (particle-operation-surface ticket 03). ⚠️ Note precisely what
 * that was and was not: `:` is LEGAL to {@see Key} since registry-kernel ticket 30 widened the segment
 * charset, so `tenants:suspend` parses — as ONE segment. The colon was never the blocker the tracker
 * recorded; it was a FLATTENING. `tenants.suspend` is TWO segments, so
 * `matches('beam.particle.operations.tenants')` enumerates a resource's operations, a relation-scoped
 * operation extends naturally to `compositions.cells.approve`, and the key is the same dot-segmented
 * shape `Rushing\PermissionCascade\Support\PermissionNamer::assemble()` produces. The 1:1 alignment
 * between the registry key and the permission name is arithmetic, not convention —
 * see {@see permissionName()}.
 *
 * ## `ability:` is THREE-state, and the third state is what makes the second auditable
 *
 * Exactly the shape `input:` already ships, for exactly the same reason — an omission and a decision
 * must not be spelled identically:
 *
 *   - a **string** — the declared authorization token, checked before `handle` runs;
 *   - **`false`** — the operation is UNGATED, deliberately, and the declaration says so. The one live
 *     instance is `Splicewire\Beam\Accounts\Ops\StopImpersonating`, where any ability at all locks the
 *     operator inside the impersonated session: while impersonating, the acting principal IS the
 *     customer and holds none of the staff entitlements that authorized the swap. That op is why
 *     "every operation is gated" cannot be the rule and "every operation DECLARES" is;
 *   - **`null`** — UNDECLARED. Today this is skipped, which means ungated apart from route middleware.
 *     This is the residue, not a design.
 *
 * **`null` is scheduled to become the DERIVED permission name** ({@see permissionName()}) rather than a
 * bypass. The flip is deliberately not made here, and the reason is measured rather than cautious: 14
 * of the 21 operations registered in the flagship app declare nothing, none of their derived names
 * exists in any host's ability universe, and an undefined ability is a DENIED one (registry-kernel
 * ticket 27 D1 pinned that exact failure for the registry authorizer). Flipping in one act would 403
 * fourteen shipped endpoints rather than gate them. The gate on the flip is the count of remaining
 * `null`s reaching zero — which {@see UngatedOperationAudit} measures, so the
 * count is a check rather than a memory. This is the identical schedule `input:`'s own `null` → `false`
 * flip is under.
 *
 * ## Which authorization PLANE a declared ability is checked on
 *
 * {@see AbilityResolver} keeps two deliberately unequal planes, and `abilityModel:` is the discriminator
 * — also three-state, for the same reason:
 *
 *   - **`null`** — the resolved `{id}` instance is the subject ⇒ the per-action (policy) plane;
 *   - a **class-string** — that model is the subject ⇒ still the per-action plane, on a cross-model
 *     subject (`fragment_url_batch.run` authorizes `create` on `Fragment`, not on the batch);
 *   - **`false`** — NO subject ⇒ the subject-free ENTITLEMENT plane (`entitlement:{key}`).
 *
 * The third state is the declared override the derivation needs. Its live instance — the two
 * `ux.author` operations in `splicewire/laravel-beam-ux` — was flipped onto it by
 * particle-operation-surface ticket 08, and what the enumeration found there is worth keeping next to
 * the flag: the mismatched declaration had been answering CORRECTLY, by accident, in all seven hosts
 * that mount it. `BeamUxEntry` carries no policy anywhere, so Laravel's Gate fell through the
 * subject-bearing branch to the bare `ux.author` ability `beam-accounts` defines as
 * `fn ($user) => $user->can('entitlement:ux.author')`, and the surplus subject argument was silently
 * ignored by that closure. Both planes were measured identical, user for user, across 28 users in five
 * hosts, guests included.
 *
 * So `abilityModel: false` there bought no behaviour change — it bought the removal of two accidents
 * the right answer was resting on (no policy on the subject model; `beam-accounts` installed to define
 * the alias). That is the general lesson for this slot: a wrong-plane declaration does not have to be
 * OBSERVABLY wrong to be worth declaring right, and a fall-through that happens to agree is not the
 * same fact as a declaration that says so.
 *
 * ## `subject:` — WHAT the operation resolves before host code runs, and why `$model` stayed
 *
 * particle-operation-surface ticket 02. Subject means the CONTEXT an operation runs in, not what it
 * hands back, and it is a polymorphic slot rather than an enum — see
 * {@see ResolvesOperationSubject}. Three implementations ship; `null` means {@see RecordSubject},
 * which is what every declaration had implicitly, so the slot is a pure addition and no declaration
 * site moved.
 *
 * What the default now does differently is the point: it resolves through the RESOURCE's backing and
 * applies the resource's declared `scope` / `includes` / `routeKey`, rather than running a bare
 * `$model::query()->findOrFail($id)`. So an operation inherits the resource's row-level gate
 * (ADR-0156 §83) instead of the gate being CRUD-only — which it was, measurably: an operation on a
 * `whereVisible()`-scoped resource, declaring no ability, reached rows the read path correctly hid.
 *
 * ⚠️ **`$model` therefore did NOT delete**, and this is a correction to the plan rather than a
 * deferral. It has a real remaining job: it is the fallback subject class for an operation registered
 * against a resource key that is not a registered particle resource — the shape `beam-accounts`'
 * `Sharing::attachTo($resourceKey, $model)`, `beam-rank`'s `Resources::attachTo()` and
 * `beam-market`'s `market-products.*` all use, 13+ live sites. For those there is no resource to
 * resolve through, and the declaration's model is the only thing that knows what to load.
 *
 * ## `signed:` — a validly-signed request is itself a credential
 *
 * api-surface-coherence ticket 95. Before this slot existed, beam had NO notion of a signed request,
 * and the gap bit the SAME operation twice through two different slots — which is the argument for a
 * declaration rather than a patch at either site:
 *
 *   - through `ability:`, because `AbilityResolver` only ever asks about an ACTOR, and the holder of a
 *     short-lived signed link is anonymous by construction. Declaring an ability would 403 the signed
 *     link before the handler ever ran, so `beam-accounts`' `LogInAsUser` hand-rolled the whole gate;
 *   - through `input:`, because `URL::temporarySignedRoute()` appends `?expires=…&signature=…` and
 *     {@see ParticleOperationController::rejectInput()} could not tell those from caller payload, so
 *     `input: false` would 422 every signed link.
 *
 * One declaration closes both. `signed: true` says *this operation accepts a validly-signed URL as an
 * admitting credential*, and the framework then:
 *
 *   1. treats a valid signature as satisfying `ability:` — the ability is skipped, not re-checked
 *      against a null actor (see {@see ParticleOperationController::invoke()}); and
 *   2. adds {@see SIGNATURE_PARAMETERS} to {@see frameworkParameters()}, so the signing pair is
 *      beam's parameter rather than the caller's on exactly the operations that can receive it.
 *
 * ### Why a slot, and not a fourth `ability:` state or a resolver plane
 *
 * Both alternatives were considered and both are wrong for the same underlying reason — *whose fact
 * is this?*
 *
 *   - **A fourth state on `ability:`** cannot express the live case. `LogInAsUser` has TWO legitimate
 *     callers holding DIFFERENT credentials: an anonymous signed-link holder and an authenticated Root
 *     operator. A state on `ability:` is exclusive with the ability, so it can express one or the
 *     other, never both. `signed:` is orthogonal precisely because the two credentials are.
 *   - **A third plane inside {@see AbilityResolver}** would break the one thing that class refuses to
 *     do: it never reads ambient authentication, because MCP over stdio has no ambient HTTP user — the
 *     actor arrives as an argument. A signature is a fact about a REQUEST, and the resolver has no
 *     request and must not acquire one. So the signature plane belongs to the TRANSPORT, next to the
 *     denial shape the transport already owns. The consequence is stated rather than hidden: MCP has
 *     no signature plane at all, and an op reached over MCP falls through to `ability:` unchanged.
 *
 * ### Deliberately two-state, where every neighbouring slot is three
 *
 * `ability:`, `abilityModel:` and `input:` are three-state because each is STAGING A FLIP — `null` is
 * residue with a scheduled meaning, and `false` exists so a reviewed decision is not spelled like an
 * omission. There is no flip scheduled here and there never will be: the safe default (no signature
 * credential) is permanent, so a `null` would only ever mean the same thing `false` does. Adding the
 * third state would be ceremony borrowed from a schedule this slot is not on.
 *
 * ### Sufficient, not required
 *
 * `signed: true` means a valid signature ADMITS; it does not mean an unsigned request is refused.
 * That is the shape the one live consumer needs (either credential admits), and it is the safe half:
 * an op that must ONLY be reachable signed is expressible today by mounting it behind Laravel's
 * `signed` middleware, which refuses before the controller runs. If a declared "signature required"
 * ever earns its place, it grows here as an enum on this slot rather than a second boolean.
 *
 * ### The scheme, and what it does NOT protect against
 *
 * There is no new signing scheme — this rides Laravel's, unchanged: `URL::temporarySignedRoute()`
 * mints and `Request::hasValidSignature()` verifies, `hash_hmac('sha256', $url, config('app.key'))`
 * compared with `hash_equals`. The secret is the host's `APP_KEY`; rotation is an `APP_KEY` rotation,
 * which invalidates every outstanding link at once and has no per-link revocation. **Replay is bounded
 * by expiry, not prevented**: anyone who obtains the URL before `expires` can use it, as many times as
 * they like. Making it single-use would need a per-link consumption ledger, which is a store beam does
 * not have and a different design; until then the mint-time TTL is the whole control, and it is stated
 * here so nobody reads `signed:` as more than it is.
 *
 * (Distinct from `Splicewire\Beam\Webhooks\HookSignature`, which signs beam's OUTBOUND webhook
 * deliveries with a per-hook HMAC secret. Same word, opposite direction, no shared machinery: that one
 * proves *we sent this*, this one proves *we minted this URL*.)
 */
class ParticleOperation implements HasRegistryKey
{
    /**
     * The query parameters Laravel's URL signer appends to a signed route, and therefore the ones an
     * operation that accepts a signed credential receives without any host having declared them.
     *
     * Spelled here rather than read from the framework because Laravel has no public constant for
     * them: `Illuminate\Routing\UrlGenerator::signedRoute()` writes `expires` and
     * `hasValidSignature()` strips `signature`/`expires` before rehashing, both as string literals.
     * A pinned copy with this note is honest; a private-API read would not be.
     *
     * @var list<string>
     */
    public const SIGNATURE_PARAMETERS = ['expires', 'signature'];

    /**
     * @param  string  $resource  the particle resource key this operation hangs off (for the route + auth)
     * @param  string  $name  the operation slug in the URL (`…/op/{name}`)
     * @param  OperationKind  $kind  read | write | task | stream — sync-call vs queueable-dispatch vs held-stream
     * @param  class-string  $model  the FALLBACK subject class — the model the `{id}` resolves to when
     *                               `$resource` names no REGISTERED particle resource. That case is
     *                               live at 13+ sites (`beam-accounts`' `Sharing::attachTo()`,
     *                               `beam-rank`'s `Resources::attachTo()`, `beam-market`'s four
     *                               `market-products.*`), which register against arbitrary host
     *                               resource keys. When the resource IS registered, the subject
     *                               resolves through ITS backing and declared gate instead — see
     *                               `$subject` and {@see RecordSubject}
     * @param  Closure  $handle  host code. Task ⇒ returns a `ShouldQueue` job built from
     *                           `($model, $request, $actor)`; Read/Write ⇒ returns a response envelope;
     *                           Stream ⇒ `($model, $request, $actor, Emitter $emit)`, pushes framed events.
     * @param  string|false|null  $ability  the authorization token checked before the op runs
     *                                      (deny-default); `false` declares the op UNGATED deliberately;
     *                                      `null` is undeclared (see the class docblock)
     * @param  class-string|false|null  $abilityModel  the subject the ability is checked against; null ⇒
     *                                                 the resolved instance (a cross-model ability names
     *                                                 its own, e.g. run authorizes `create` on
     *                                                 `Fragment`, not the batch); `false` ⇒ no subject,
     *                                                 the entitlement plane
     * @param  (Closure(mixed): mixed)|null  $respond  a Task's response projector
     *                                                 (given the refreshed model); null ⇒ a bare `{ queued: true|false }`
     * @param  class-string|false|null  $input  the Data class this op ACCEPTS — its declared payload
     *                                          contract; `false` declares it accepts nothing; `null` is
     *                                          undeclared (see the class docblock)
     * @param  class-string|array<string, list<class-string>>|null  $output  the Data class this op RETURNS; on
     *                                                                       a Stream, an event-name → payload-list
     *                                                                       map (see the class docblock)
     * @param  bool  $signed  whether a validly-signed URL is an ADMITTING credential for this op — it
     *                        satisfies `ability:` and makes `expires`/`signature` framework parameters
     *                        rather than caller input (see the class docblock)
     * @param  ResolvesOperationSubject|class-string<ResolvesOperationSubject>|null  $subject  WHAT this
     *                                                                                         operation resolves before `handle` runs. `null` ⇒ {@see RecordSubject},
     *                                                                                         which is what every declaration had implicitly. ⚠️ Spell it
     *                                                                                         `RecordSubject::class` or `new ActorSubject` — a static factory call
     *                                                                                         (`Subject::record()`) is not a constant expression and FATALS inside the
     *                                                                                         `#[ParticleOp]` twin, so the two declaration sites would not be able to
     *                                                                                         say the same thing. See {@see SubjectResolvers}
     */
    public function __construct(
        public string $resource,
        public string $name,
        public OperationKind $kind,
        public string $model,
        public Closure $handle,
        public string|false|null $ability = null,
        public string|false|null $abilityModel = null,
        public ?Closure $respond = null,
        public string|false|null $input = null,
        public string|array|null $output = null,
        public bool $signed = false,
        public ResolvesOperationSubject|string|null $subject = null,
    ) {
        $this->assertOutputMatchesKind();
    }

    /**
     * A Stream's `output` is an event-name map and every other kind's is a single class — the two are not
     * interchangeable, so a mismatch is a declaration bug and fails at registration rather than silently
     * generating the wrong client type.
     *
     * This runs in the constructor deliberately: attribute discovery and manual `register()` both build this
     * object, so validating here is the one place that catches both paths.
     */
    private function assertOutputMatchesKind(): void
    {
        if ($this->output === null) {
            return;
        }

        $isStream = $this->kind === OperationKind::Stream;

        if ($isStream && ! is_array($this->output)) {
            throw new InvalidArgumentException(
                "Particle operation [{$this->key()}] is a Stream, so its `output:` must be an event-name map "
                .'of `[eventName => [DataClass, ...]]` — a stream emits discrete typed events under distinct '
                .'wire names, not one resolved payload.'
            );
        }

        if (! $isStream && is_array($this->output)) {
            throw new InvalidArgumentException(
                "Particle operation [{$this->key()}] is a {$this->kind->name}, so its `output:` must be a "
                .'single Data class-string. An event-name map is only meaningful on a Stream.'
            );
        }
    }

    /**
     * The operation's own address, root-free — `{resource}.{name}`.
     *
     * Root-free on purpose: {@see ParticleOperationRegistry} stamps `beam.particle.operations` on the
     * way in ({@see BasicRegistry::door()}), and an entry that spelled the
     * root would be re-keyed at every declaration site the day the root moves. "You cannot register
     * outside your own root because you never spell the root."
     */
    public function registryKey(): RegistryKey
    {
        return Key::parse($this->key());
    }

    /**
     * The same address as a plain string — kept because it is what error messages interpolate and what
     * the mount stamps onto the route.
     */
    public function key(): string
    {
        return "{$this->resource}.{$this->name}";
    }

    /**
     * The permission name this operation would be gated on if it declared nothing.
     *
     * It is {@see Key()}, unchanged — that is the whole finding, not a coincidence to be papered over
     * with a mapping table. `PermissionNamer::assemble('market-products', 'approve')` produces
     * `market-products.approve`; the registry key minus the stamped root is `market-products.approve`.
     * The two vocabularies already agreed; the `:` separator was the only thing that hid it.
     *
     * ⚠️ **Measured, and it is 20 of 21 rather than 21 of 21.** `PermissionNamer::assemble()` runs
     * `Str::slug()` over each part, so a resource key spelled with underscores diverges: live in the
     * flagship app, `fragment_url_batch` + `run` derives `fragment_url_batch.run` here and
     * `fragment-url-batch.run` there. That is one resource key estate-wide, and it is the one that is
     * not in the house spelling — {@see Key}'s own docblock states dotted-kebab is the spelling for
     * anything newly written and that `_`/`:` exist only so a domain that ALREADY spells its identity
     * that way is not forced through a rename. So the divergence is not repaired by slugging here (that
     * would make the key and the permission name two different strings, which is the thing this method
     * exists to deny); it is repaired by spelling the resource key in kebab. Recorded rather than
     * papered over, because it will bite exactly once — the day someone gives that op a permission.
     *
     * ⚠️ Not yet consulted by {@see ParticleOperationController} — see the class docblock for the
     * flip's schedule and why it is staged rather than landed.
     */
    public function permissionName(): string
    {
        return $this->key();
    }

    /**
     * Whether this operation's authorization is UNDECLARED — the residue state, and the one the flip
     * closes. `false` (deliberately ungated) is a declaration and answers `false` here.
     */
    public function gateUndeclared(): bool
    {
        return $this->ability === null;
    }

    /**
     * The parameters the FRAMEWORK accepts on THIS operation, as opposed to the ones the host declares
     * through `input:`.
     *
     * Two sources, and both are properties of the operation rather than of any one of them:
     * {@see OperationKind::frameworkParameters()} contributes the kind's (a Task's `?async`), and
     * `signed:` contributes {@see SIGNATURE_PARAMETERS}. The union lives here rather than on the enum
     * because the enum cannot see a declaration — which is exactly how ticket 95's second bite
     * happened: `rejectInput()` asked the KIND what the framework accepts, the kind had no way to know
     * the mount was signed, and `expires`/`signature` came back as caller input.
     *
     * Read by {@see ParticleOperationController::rejectInput()} (what `input: false` forgives) and by
     * `ParticleOperationParameterStrategy` (what the reference publishes), so the branch that enforces
     * and the document that describes cannot disagree.
     *
     * @return list<string>
     */
    public function frameworkParameters(): array
    {
        return [
            ...$this->kind->frameworkParameters(),
            ...($this->signed ? self::SIGNATURE_PARAMETERS : []),
        ];
    }
}
