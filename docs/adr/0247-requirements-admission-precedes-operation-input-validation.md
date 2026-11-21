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

- **The slot.** `Splicewire\Beam\Agreements\Requires` is a final value class spellable in an attribute: `new Requires('terms', scope: 'party', on: 'both')`. `agreement` is the Agreement key (never a class: beam does not know agreements); `scope` is `party`, `organisation` or `subject`; `on` is `read`, `write` or `both` and is meaningful only on a resource. Both are validated at construction. `requires: list<Requires>|null` is the last slot of `#[ParticleOp]` / `ParticleOperation` and of `#[ParticleResource]` / `ParticleResource`, carried by `AttributedParticleDiscovery`. Omitted (`null`) declares none; no audit counts the omission.
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

## Amendment (agreements 08, beam core)

Beam exposes one `Agreements\AssentRecorder` container port with `record(Model $subject, WriteRequirement $requirement): void`. Its default `NullAssentRecorder` does nothing, so hosts that install no recording adapter keep the existing write behavior. An agreements adapter may replace the binding. This is a single port, not a registry on `ParticleWriter`.

`PersistStage` calls the port immediately after saving a model and before the optional after-write callback, whenever the `WriteContext` carries a `WriteRequirement`. REST and Frame create/update always build one, even when no Requirement is declared, so the recorder runs on every transport write. An adapter must filter using `RequirementDeclarations` and/or `submittedMembers`, treating other writes as a no-op. `WriteRequirement::subject` is null on create and is the existing model on update. The call is inside the transaction on the subject model's own connection, after the subject has its identity and before that transaction commits. A thrown recording failure therefore rolls back that subject write. The call deliberately bypasses `ScopedWriteGate`: recording authority belongs to the agreements adapter, not to the model write gate.

Standard REST and Frame create/update writes, `ActorOwnedParticleBacking`'s custom pipeline, and collection persistence all reuse `PersistStage`, so they reach the same port. Dedupe modes `ignore` and `reject` that persist nothing do not call it; whether a repeated capture records is the seam half's decision, and it must preserve the byte-identical `ignore` oracle. Deletes are out of scope. Write-kind operation handlers do not use this stage and Beam supplies no operation transaction; a handler that records an Assent calls this same port inside its own transaction before its first irreversible effect. The seam half of agreements 08 owns the registration/doctor check for handlers that declare a creates-its-subject Requirement without declaring that they record it.

If the subject is on a tenant connection and the Assent ledger is central, a failed central append rolls back the tenant write. The two connections are not a distributed transaction: a successful central append can remain if the tenant transaction later rolls back, as described in agreements decision 17.
