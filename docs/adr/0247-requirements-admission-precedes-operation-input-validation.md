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
