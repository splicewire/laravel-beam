# ADR-0247: A requirement admission precedes operation input validation

Status: proposed (agreements 01; owner-approved PRD decisions 12, 13 and 15)

## Context

Ability answers whether an actor may attempt a behavior. A Requirement answers which current Agreements remain outstanding, and can yield several actionable records. Folding these into one ability loses the list and can disclose it to a caller who cannot invoke the behavior.

The beam core therefore exposes one transport-neutral `RequirementAdmission` seam, backed by a `RequirementResolver`. The resolver receives the acting party, the behavior key, the subject when present, and submitted agreement members keyed by input member name. It returns data only. The core default resolver reports none, preserving behavior for hosts without an agreements adapter. Admission returns a declared `RequirementRefusalData` payload or no refusal; it never records assent and never constructs an HTTP response.

## One operation request

For a mounted particle operation, the order is:

1. Resolve the declared operation and its subject.
2. Check the operation's declared ability using the existing `AbilityResolver`.
3. Ask `RequirementAdmission` with the actor, operation key, subject and declared agreement members submitted in the request.
4. If the result lists outstanding Requirements, HTTP returns that declared body with status 428. Otherwise validate the operation's own input and invoke its handler.

An ability denial remains 403 and happens before requirements are queried. A requirement refusal happens before ordinary operation input validation so the client receives the actionable Agreement list rather than a secondary validation error.

The refusal lists each Agreement key, version, digest, kind and title; its scope and subject; where to read it; and exactly one remedy: either the accept operation or the name of the Agreement member to submit. The body is declared through the operation error slot at 428 so clients can generate a type for it.

If the resolver reports outstanding Requirements for an operation without `errors: [428 => RequirementRefusalData::class]`, the controller fails loudly with a `LogicException`; it does not invent an undeclared response body. This is the current runtime guard, not an early registration check. Tickets 04 and 05 must carry this contract forward: when an operation declares Requirements (including 05's `requires` slot), registration must require or imply `errors:428`, or a doctor audit must flag the missing declaration.

The same transport-neutral admission seam can be called by any future transport that invokes particle operations. No agent transport currently invokes `ParticleOperationRegistry`; agreements 01 does not add an MCP operation path. The first such transport must call this admission seam and may not record assent as part of refusal.

### Open points for tickets 04 and 05

- `AgreementMemberData` contains a key, version and digest, with no affirmative flag. Whether the presence of that member is sufficient to count as affirmative remains open for the agreement adapter.
- For a signed-link request (`$admittedBySignature`), which party the requirements plane should receive remains open. The adapter must settle this without changing the transport-neutral admission contract.

## Alternatives

- **Return forbidden with an error code:** rejected because an ability denial says the actor cannot act, while an outstanding Requirement says what Agreements are needed; the response must carry a possibly multi-item list and remedies.
- **Use Laravel's locked response status for password confirmation:** rejected because that status represents a session confirmation challenge, not the durable Agreement state and typed list. HTTP 428 identifies the required precondition while letting the declared body carry the needed Agreements.
- **Gate command-line invocation:** rejected. Beam abilities do not gate command-line invocation, which has no caller transport party; a command that needs an Assent records it through the ledger explicitly.

## Scope

This ticket wires particle operations only. Requirement declarations on resources, ordinary routes, route middleware and workflow steps are integrated by the tickets that own those surfaces. The shared admission seam does not depend on the agreements package, and the agreements package may bind a resolver without adding a reverse dependency from beam.

## Amendment (agreements 04)

A host may attach a Requirement, by behavior key (`ParticleOperation::key()`, `{resource}.{name}`), to an operation it does not own. That operation's author typically declared no `errors:428`, so the guard above would throw the first time the requirement is outstanding. Ticket 04 closes the gap this ADR left open for it:

- **The port.** `Splicewire\Beam\Agreements\RequirementDeclarations::declares(string $behavior): bool` reports which behaviors carry a host-attached Requirement. Beam binds `NullRequirementDeclarations` (declares nothing) with `bindIf`, beside the `RequirementResolver` default; the agreements package binds its own.
- **The implied 428.** `ParticleOperation::declaredErrors()` returns the author's `errors:` plus `428 => RequirementRefusalData::class` when the port declares the operation's behavior and the author declared no 428. `$errors` itself stays the author's declaration. Every reader of an operation's declared refusals reads `declaredErrors()`: the controller's 428 guard and the generated types (`DeclaredParticleTypes`). The OpenAPI spec is emitted outside beam (laravel-beam-docs `ParticleResponseStrategy`), which must read the same method for the spec to show the implied 428.
- **Byte-identical without a seam.** Under the Null port `declaredErrors()` returns `$errors` unchanged, so a host without the agreements seam answers, documents and generates exactly as before.
- **The audit.** An author's own 428 is never overwritten. If it names a different class while the port declares the behavior, the controller's guard still throws, and `RequirementRefusalDeclaredAudit` (check `requirements.refusal-declared`, registered as a gate) fails the doctor for that operation before a request finds it. It passes trivially under the Null port.

This satisfies the contract above for ticket 04: registration of a Requirement on an operation implies `errors:428`, or the doctor flags the conflicting declaration. Ticket 05's `requires` slot carries the same contract through the same port.

## Amendment (agreements 05)

Ticket 05 lets a declaration attach its own Requirements, and extends admission to resources.

- **The slot.** `Splicewire\Beam\Agreements\Requires` is a final value class spellable in an attribute: `new Requires('terms', scope: 'party', on: 'both')`. `agreement` is the Agreement key (never a class: beam does not know agreements); `scope` is `party`, `organisation`, `subject` or `occurrence`; `on` is `read`, `write` or `both` and is meaningful only on a resource. Both are validated at construction. `requires: list<Requires>|null` is the last slot of `#[ParticleOp]` / `ParticleOperation` and of `#[ParticleResource]` / `ParticleResource`, carried by `AttributedParticleDiscovery`. Omitted (`null`) declares none; no audit counts the omission.
- **Behavior keys.** An operation's behavior stays `ParticleOperation::key()`, `{resource}.{name}`. A resource is two behaviors: `{resource}:read` (list and detail) and `{resource}:write` (create, update, delete), from `ParticleResource::readBehavior()` / `writeBehavior()`. The colon is deliberate: an operation key is dotted, so a resource behavior can never collide with an operation named `read` or `write`.
- **The port, additively.** `RequirementDeclarations` keeps `declares()` and gains `requirementsFor(string $behavior): list<Requires>` and `all(): array<behavior, list<Requires>>`. In every implementation `declares($b) === (requirementsFor($b) !== [])`.
- **The slot defaults.** Beam binds, with `bindIf` where the Null classes were bound, `SlotRequirementDeclarations` (answers from the `requires:` slots of the registered operations and resources) and `SlotRequirementResolver`. With no agreements seam installed, the resolver reports every slot Requirement of the attempted behavior as outstanding with `kind: 'unregistered'`, the key as title, version 0, an empty digest and read URL, and no remedy: a slot-carrying behavior refuses everyone as an unknown Agreement. With no slot anywhere both answer exactly as the Null classes did, so responses, the manifest and generated types are byte-identical. A seam binds its own pair with a plain `bind` and reads the slots through `SlotRequirementDeclarations`. The doctor check `requirements.unseamed-slot` (`UnseamedRequirementSlotAudit`, a gate) fails while any slot exists and beam's slot resolver is still bound, naming each behavior and key.
- **The `unregistered` remedy rule.** `OutstandingRequirementData` still requires exactly one remedy (accept operation or input member), except for `kind: 'unregistered'`, which may carry neither: no seam knows the Agreement, so there is nothing to offer. The generated type is unchanged (both remedies were already nullable).
- **The implied 428** of agreements 04 covers the slot unchanged: the slot default reports an op's own slot through the port, so `declaredErrors()` implies `428 => RequirementRefusalData` for it.
- **Resource admission order.** As for operations, the verb's ability comes first and the Requirement second, so a caller refused the ability gets 403 and never learns the Requirements. REST (`ParticleController`) asks after the read guards for `index`, after the view/ownership checks for `show`, and after the delete authorization (before deleting) for `destroy`. Create and update are asked by the write pipeline's `RequirementAdmissionStage`, placed right after `AuthorizeStage` (after a backing's own ownership stage for actor-owned storage); the resource transport writes through `ParticleWriter::requiring(WriteRequirement)` (a copy, so `write()` keeps its signature for subclasses and fakes) (behavior `{resource}:write`, the record for an update, null for a create, submitted members), and a caller-composed stage list without the stage gets it inserted after its first `AuthorizeStage`. A refusal throws `RequirementRefused`, rendered as the 428 body; the frame handler (`ParticleFrameResourceHandler`, same points) turns it, and its own read/delete refusals, into an `HttpResponseException` with that body. A resource's input DTO is still parsed before the write gate, as before this ticket; only the gate-then-requirement order is new.
- **Guests.** A read Requirement refuses a guest even where `publicRead` is declared. A Public Read admits a guest past the read ability, never past a Requirement: a guest has no party to have assented. Declare the Requirement `on: 'write'` to keep guest reads open.
- **Route manifest.** Each entry of `ParticleRouteManifestSource` gains `requires: [<agreement keys>]` (distinct keys, declaration order) only when the route's behavior has Requirements per the port: operations by their key, `index`/`show` as `:read`, `store`/`update`/`destroy` as `:write`. Keys only. The key is omitted, never an empty list, when there are none.
- **Ability map.** `CanMapBuilder::forPrincipal()` adds `agreement:{key}`, asked of the Gate, for every distinct key whose Requirement is party- or organisation-scoped across `RequirementDeclarations::all()`. Subject-scoped Requirements are per record and stay out of the flat map. Nothing is added when nothing is declared.
- **Generated types.** `DeclaredParticleTypes` notes `RequirementRefusalData` for a resource whose `:read` or `:write` behavior has Requirements, as the operation loop already does through `declaredErrors()`.

## Amendment (agreements 07)

Ticket 07 gives an ordinary (hand-written) route the same gate as a declared operation.

- **The behavior key of a route is `route:{name}`.** It is the route's name with a `route:` prefix (lead ruling Q-07a).
  - The prefix is needed: beam names a particle operation's own route after the operation key (`{resource}.{op}`), so a bare route name would collide with operation behaviors and gate operation routes twice.
  - An operation key is dotted and has no colon, so it can't start with `route:`. A resource behavior is `{resource}:read|write`, so `route:read` and `route:write` are RESERVED: no resource may be keyed `route`.
  - A route with no name has no behavior key. See the doctor check below.
- **The middleware.** `Splicewire\Beam\Agreements\Http\RequireAgreement`, aliased `agreement`, is in beam core (lead ruling Q-07b).
  - Spelling:
    - `->middleware('agreement:terms')` is party scope;
    - `agreement:data-use,report` is subject scope, where the named route parameter's BOUND record is the subject;
    - `agreement:terms,,organisation` is organisation scope.
  - The helper is `RequireAgreement::using($agreement, subject:, scope:)`.
  - It holds no agreement logic. `RouteRequirementGate` takes the actor from `ActorPort` and asks the SAME `RequirementAdmission` (and so the same `RequirementResolver`) the operation transport asks, with no submitted members.
  - An unnamed route carrying it throws a `LogicException` on its first request, and the gate check `requirements.route-unnamed` (`UnnamedRequirementRouteAudit`) fails at boot, naming the URI.
  - **Subject scope needs a subject parameter.** `agreement:terms,,subject`, `RequireAgreement::using(..., scope: 'subject')` without `subject:`, or a subject-scoped Requirement a provider attaches to `route:{name}` on a route that names no parameter, is REJECTED, because the person could accept forever and never pass:
    - `using()` throws at once;
    - the gate throws a `LogicException` naming the route before asking anything;
    - the doctor check `requirements.route-subject` fails at boot (review P2, lead ruling).
- **The declaration reaches the port.** `SlotRequirementDeclarations::all()` also reads, from the router on every call, each named route's `agreement:` middleware into `route:{name}`. The resolver, the ability map (`agreement:{key}`) and any other port reader therefore see a route's Requirement like a slot's. A seam that reads the slots through this class sees them too.
- **Attaching without editing.** A host may attach a Requirement to `route:{name}` (for example from a provider, through the seam's registry) for a route it doesn't own (lead ruling Q-07c).
  - Beam appends one global `EnforceRouteRequirements` to the host's `web` and `api` groups, through the kernel like localization's `ResolveLocale`.
  - It asks admission only for a route whose `route:{name}` the port declares. For every other route it is INERT: with no seam it returns at once; with one it makes one port lookup and never calls the resolver or the database (a test counts both).
  - A request is asked at most once, whichever middleware reaches it first.
- **Order.** Both middlewares are placed in the priority list after `StartSession`, `AuthenticatesRequests` / `Authenticate` AND `SubstituteBindings` (`addToMiddlewarePriorityAfter`). The route's own authentication therefore refuses a guest first, and the gate never runs for that request. The subject parameter is already the bound record.
- **No seam, no gate.** While the bound resolver is still beam's own `SlotRequirementResolver` or `NullRequirementResolver`, both middlewares pass every request (ticket 07's criterion), and a route answers exactly as without the middleware. This DIFFERS from amendment 05's rule for operation and resource slots, which refuse as `unregistered`:
  - a route gate is opt-in per host deployment (the seam is what knows Agreements);
  - a hand-written route has no declared 428 body for a client to expect.

  `requirements.unseamed-slot` therefore ignores `route:` behaviors.
- **The refusal on a route** (lead ruling Q-07d):
  - A DATA request (`expectsJson()` and no `X-Inertia` header) gets status 428 and the declared `RequirementRefusalData` body, exactly as an operation's.
  - Every other request (a browser visit, or an Inertia visit, full or partial) is a PAGE visit. It is redirected (303) to the seam's prompt route, `beam.agreements.required`, with the refusal, the behavior, the subject's key, the intended destination and a way back in the session (`agreements.required`), like password confirmation's `url.intended`.
  - The intended destination of a GET or HEAD is its full URL. For any other method it is the previous URL: the request is NOT replayed.
  - With a seam but no prompt route, a page visit gets the 428 too.
  - **Only with a session.** The page-visit path stores the destination in the session, so it applies only when the request HAS one. A session-less request (a standard stateless `api` group) gets the data refusal, 428 plus the declared body, whatever it accepts (review P2, lead ruling).
  - **Same-origin destinations only.** `url()->previous()` trusts the raw `Referer`, so `back` and a non-GET's `intended` keep a destination only when it is same-origin: a relative path, or an http(s) URL with the app's own scheme, host and port. Anything else falls back: `back` to the home URL, and a non-GET's `intended` to the home URL (a GET's `intended` is the gated URL itself). The prompt page checks the same rule again before it links or navigates (review P2, lead ruling).
- **The prompt page** is the seam's to mount and `@splicewire/beam-agreements`' to draw, reusing the step-up dialog (lead ruling Q-07e). Accepting returns the person to the intended destination; declining leaves them on the prompt with a way back.
- **Not here:** a page's record bound through its resource's read (E17) stays out of scope.

## Amendment (agreements 08, beam core)

Beam exposes one `Agreements\AssentRecorder` container port with `record(Model $subject, WriteRequirement $requirement): void`. Its default `NullAssentRecorder` does nothing, so hosts that install no recording adapter keep the existing write behavior. An agreements adapter may replace the binding. This is a single port, not a registry on `ParticleWriter`.

`PersistStage` calls the port immediately after saving a model and before the optional after-write callback, whenever the `WriteContext` carries a `WriteRequirement`. REST and Frame create/update always build one, even when no Requirement is declared, so the recorder runs on every transport write. An adapter must filter using `RequirementDeclarations` and/or `submittedMembers`, treating other writes as a no-op. `WriteRequirement::subject` is null on create and is the existing model on update. The call is inside the transaction on the subject model's own connection, after the subject has its identity and before that transaction commits. A thrown recording failure therefore rolls back that subject write. The call deliberately bypasses `ScopedWriteGate`: recording authority belongs to the agreements adapter, not to the model write gate.

Standard REST and Frame create/update writes, `ActorOwnedParticleBacking`'s custom pipeline, and collection persistence all reuse `PersistStage`, so they reach the same port. Dedupe modes `ignore` and `reject` that persist nothing do not call it; whether a repeated capture records is the seam half's decision, and it must preserve the byte-identical `ignore` oracle. Deletes are out of scope. Write-kind operation handlers do not use this stage and Beam supplies no operation transaction; a handler that records an Assent calls this same port inside its own transaction before its first irreversible effect. The seam half of agreements 08 owns the registration/doctor check for handlers that declare a creates-its-subject Requirement without declaring that they record it.

If the subject is on a tenant connection and the Assent ledger is central, a failed central append rolls back the tenant write. The two connections are not a distributed transaction: a successful central append can remain if the tenant transaction later rolls back, as described in agreements decision 17.

### Amendment (agreements 08, occurrence scope)

`Requires::SCOPES` also accepts `occurrence`. This is a request-local precondition: only an affirmative `AgreementMemberData` in the same request, naming the Agreement version and digest currently in force, can satisfy it. A prior ledger entry never satisfies an occurrence Requirement. Beam without an Agreements seam keeps the 05 rule: a declared slot is refused as `unregistered`; accepting the scope spelling does not register an Agreement or make the slot pass. The agreements adapter owns member-declaration validation and recording the submitted form against the resulting subject inside the write transaction. A flat `agreement:{key}` Gate cannot satisfy an occurrence Requirement because it receives no request member, and therefore answers false whenever that key has an occurrence-scoped declaration.
