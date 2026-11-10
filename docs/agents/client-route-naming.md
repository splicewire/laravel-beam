# Generated client names across route families

The full route name identifies the endpoint. Generated `routes.ts`, `route(...)` calls and query keys keep it verbatim.
A hook, its mutation variables, a store/state type and a stream/event type share the same derived client domain.

A host with only `api.v1.entries.index` keeps `hooks/entries.ts` and `useEntriesIndex`. An unprefixed
`entries.index` keeps those existing short names when both families are present. Only when generated route-derived
identifiers collide does the API family gain its transport prefix: `hooks/api-v1-entries.ts`,
`useApiV1EntriesIndex`, `ApiV1EntriesUpdateVars`, `useApiV1EntriesIndexStore`, `ApiV1EntriesIndexState`,
`useApiV1EntriesEventsStream` and `ApiV1EntriesEventsStreamEvent`. Other actions of that API domain follow the
same file/domain decision. A mixed domain whose actions do not collide remains unchanged.

The same distinction inside an operator map uses `api-operator`; the existing tenant/operator collision rule then
qualifies the operator domain where needed. Typed and streamed entries participate in the same decision, so
an operator stream never borrows the tenant file's client binding.

Qualification does not deduplicate routes or alter payloads, verbs, parameters, query keys, response projection or
stream event shapes. Stores remain opt-in and apply only to parameterless reads; parameterized reads have hooks.
Store-derived names participate in collision detection only when stores are enabled.

If qualification collides with another domain (for example a literal `api-v1-entries` or `operator-entries` domain),
generation throws a `LogicException` naming the file/identifier and both routes. Route-derived identifiers are
reserved across generated hook/store modules, including Vars, private state types and stream event types. This
also catches distinct domain spellings that collapse to the same Studly export. No arbitrary suffix or silent
endpoint substitution resolves an otherwise ambiguous model. DTO aliases retain their existing basename-warning
policy; this guard does not change that separate policy.
