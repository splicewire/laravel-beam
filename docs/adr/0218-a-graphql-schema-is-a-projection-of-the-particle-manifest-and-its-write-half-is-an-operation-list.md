# ADR-0218 — A GraphQL schema is a PROJECTION of the particle manifest, and its write half is an operation-list

**Status:** accepted — **as constraints only. This ADR does not decide to build a GraphQL surface, and
the gate that would decide it stays open.**
**Date:** 2026-09-01
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/particle-graphql-projection/` — ticket 01 (the gating
question) closes on this ADR; ticket 02 (the reader-divergence census) stays open and is unaffected.
**Supersedes nothing. Extends:** ADR-0211 (a generated artifact is a core-mounted route behind a
source seam), ADR-0212 (a resource declares what backs it, not how it is shaped), ADR-0213 (a port is
the interface, an adapter is the implementation).

## Context

An `/improve-codebase-architecture` pass on 2026-08-26 asked *"can we serve particle resources over
GraphQL, and where does that sit relative to `laravel-data` and the particle doctrine?"* Seven parallel
agents went out. The GraphQL question was chartered as its own map, and its research produced a set of
constraints that hold **whether or not the surface is ever built** — which is the only reason this ADR
exists. Recording them costs one afternoon and permanently forecloses the wrong version of the project;
re-deriving them costs the same research twice, and the research contains two retracted figures that a
second pass would very likely re-import.

**The estate's premise is one declaration.** The particle doctrine holds exactly **three** legal
declaration sites — `#[ParticleResource]`, `#[ParticleOp]`, and the
`#[ResponseFromData]`/`#[RequestFromData]`/`#[StreamsFromData]` trio for non-particle surfaces — and no
fourth. That one declaration is what locks the generated TypeScript, the OpenAPI document and the client
SDK to the backend by construction rather than by discipline. Anything that adds a fourth site does not
extend the doctrine; it ends it.

**Every candidate GraphQL substrate ships a declaration site of its own.** Measured 2026-08-26 —
`webonyx/graphql-php` is installed in **0 of 119 roots**, and all four candidates sit on it, so the
choice between them is a choice of *authoring ergonomics*, which is precisely the thing this estate does
not need because it does not author.

| substrate | its declaration site | verdict |
| --- | --- | --- |
| `webonyx/graphql-php` | none — `Schema` built in PHP | the wheel; everything else depends on it |
| `nuwave/lighthouse` | SDL `.graphql` files + directives. Its own docs say native PHP types lose *"a large portion of Lighthouse functionality"* | viable **only** if the SDL is generated — `BuildSchemaString` accepts SDL as a returned string, and that event is the seam that makes a projection possible at all |
| `rebing/graphql-laravel` | PHP `Type`/`Query`/`Mutation` classes named in config | viable, thinner; class-per-type **is** a fourth site if hand-written |
| `optiGov/eloquent-graphql` | `@property` docblocks on Eloquent models | **disqualified twice** — it puts the wire shape back on the model, which is the exact inversion ADR-0212 removed; and it is 4 stars, 0 forks |
| `overblog/GraphQLBundle` | YAML / attributes / PHP builders | out of scope — Symfony kernel, DI and bundle system |

**GraphQL's write half is weak by specification, and its own authors say so.** Mutations are
spec-confined to flat, serial, root-level execution (§6.2.2 / §6.3.1); nested mutations, spec issue
#252, never left Strawman. graphql.org concedes verbatim: *"there's no way for GraphQL to revert the
successful portions."* Partial-failure semantics are undefined — spec issue #277, opened by
graphql-ruby's author in 2017, **closed with no normative resolution**. Input polymorphism took seven
years (`@oneOf`, RFC3, May 2025) where output unions shipped on day one.

**And the shape that answers it is one this estate already ships.** Marc-André Giroux, on atomic batch
writes: *"if any one of those fail, what do you think happens here? Nobody knows."* In the same place he
reports that **Dan Schafer — GraphQL's co-creator — suggested a single mutation field taking a list of
operations as variables.** That is `ParticleOperation`, arrived at independently: `POST
/{resource}/{id}/op/{name}` with a declared `input:`, `kind:` and `ability:`. commercetools' `actions:
[…]` update API is the same shape from a third direction. Three unrelated parties converged on
operation-lists over CRUD mutations, which is stronger evidence than any of them alone.

**On whether the estate has the condition GraphQL is priced for — unresolved, deliberately.** Adoption
is **33%** (Postman, n≈5,700); Gartner's 2026 Hype Cycle places GraphQL in the Trough of
Disillusionment. Zalando's public API guidelines permit it at the BFF edge and ban it as a service
transport; Hasura's own founder calls *"GraphQL on REST"* an anti-pattern absent a real query-plan
compiler. **Composite Schemas is Stage 0: Preliminary, 27 months after announcement — nothing may be
architected on it.** It is not dying (Meta Platinum, spec edition ratified, Airbnb open-sourced Viaduct
at 1.0); it settled into a niche, and whether this estate is in that niche was claimed and never
evidenced.

> ⚠️ **Two widely-circulated figures were retracted during that research and must not be re-imported.**
> A **"61% adoption"** number is traceable to a GraphQL CMS surveying its own community. A Gartner
> prediction of **">50% of enterprises by 2025"**, made in December 2021, **did not happen** — the
> measured figure is the 33% above. Related: the State of GraphQL survey ran **once**, in 2022, so it is
> not a trend line; and several *"X moved off GraphQL"* claims are false (Netflix, Stack Overflow, Yelp,
> Coinbase, Pinterest, Atlassian, Artsy). Real removals exist and are smaller: Open States, Fauna,
> Netlify Graph, Prisma.

## Decision

### 1. A GraphQL schema is a PROJECTION of the particle manifest. It is never a declaration site.

If this estate serves GraphQL, the SDL is a **generated artifact** — a fifth one, emitted from the
particle registries alongside `generated.d.ts`, `resources/schemas`, `routes.ts` and `openapi.yaml`.
Types, fields, and arguments are derived: types from the declared Data classes, backing from
`ResourceBacking` per ADR-0212, arguments from `Rushing\DataFilters\Reflection\FilterReflector`.

**No `.graphql` file is authored, no `Type`/`Query` class is hand-written, and no `@property` docblock
is added to a model.** A hand-written schema element is a fourth declaration site by definition, and the
doctrine has three.

This is ADR-0211's ruling applied to a second artifact rather than a new idea: the OpenAPI document is
already generated, already served behind an `OpenApiSpecSource` seam, and already never authored. A
GraphQL schema is the same kind of thing and gets the same treatment.

**The practical consequence for substrate choice**: the only property that matters is whether the
substrate will accept a schema it did not author. Lighthouse's `BuildSchemaString` event returns SDL as
a string, so Lighthouse qualifies **despite** being SDL-first — the SDL is a build output, not a source
file. `rebing/graphql-laravel` qualifies if the `Type` classes are generated rather than written.
`optiGov/eloquent-graphql` cannot qualify at any price, because its declaration site is the Eloquent
model, and putting the wire shape back on the model is precisely the inversion ADR-0212 removed.
`overblog/GraphQLBundle` is out of scope on the Symfony kernel alone.

### 2. The write half is an operation-list. It is never CRUD mutations.

A generated `createX` / `updateX` / `deleteX` mutation per resource is refused. If a write surface is
built, it is a **single mutation field taking a declared list of operations** — the existing
`ParticleOperation` declarations projected as input, not a per-verb mutation family.

The reasoning is entirely in the Context above and none of it is ours: the spec confines mutations to
flat serial root-level execution, graphql.org concedes there is no rollback, partial-failure semantics
have been formally undefined since 2017, and GraphQL's co-creator suggested the operation-list shape
himself. commercetools ships it. This estate already has it.

This does not make GraphQL transactional — nothing in the spec can — but it moves the atomicity boundary
to a place the estate already owns: one operation, one write pipeline, one ability check. Per-verb
mutations put the boundary in a place nobody owns.

### 3. A projection needs a declared projection policy. The registry alone does not contain one.

Cross-map, from `rushing/laravel-popcorn` wayfinding `registry-kernel` ticket 69 (V3), measured against
the live estate and applying **verbatim** to any projection that tries to derive a surface from a
registration:

- **30% of the registry must not be mounted** (13 of 44), and the registry carries no flag saying which
  third.
- **9% of what is mounted is not in the registry** (4 of 35).
- **The URI is not a function of the key** — 2 of 31 diverge (`lineages → api/v1/lineage`,
  `market-extensions → api/v1/extensions`), and `media` is mounted at two unrelated roots.
- `#[ParticleResource]`'s constructor carries **no routing property whatsoever** across its 30 slots.

So "project every registration" is wrong for roughly a third of the manifest, and a generator that
assumes the registry is the exposure list will emit a schema that is simultaneously over- and
under-inclusive. **Any build must declare what is projected, on the declaration, rather than inferring
it** — the same conclusion ADR-0211 §7 reached for the OpenAPI stub's prefix rules, and for the same
reason: a derived boundary must be derived from something that actually carries the boundary.

### 4. `graphine` is not GraphQL, and both READMEs must say so

`rushing/laravel-graphine` and `rushing/php-graphine` are a **graph substrate** seam — nodes, edges,
traversal, Kahn/Tarjan topological sort. They have **nothing to do with GraphQL.** The name collision is
phonetic and total, and a `laravel-data-graphql` sitting beside `laravel-graphine` in the same vendor
namespace will be conflated by every future reader and every agent that greps for one and finds the
other.

**The disambiguating sentence goes into both READMEs in the same act that creates the second package** —
not afterwards, when someone notices. It follows the twelfth and thirteenth collisions ADR-0213
recorded (`Port`, `Envelope`), and it is the only one so far that can be fixed **before** the colliding
thing exists.

### 5. What this ADR deliberately does NOT decide

**It does not decide to build.** The gating question — does this estate have an external/uncontrolled
client population that would benefit from selection-set queries, or genuine federation across hosts that
the flagship does not already compose in-process — was **claimed and never evidenced**. Both conditions
were asserted by the owner on 2026-08-26 with an explicit invitation to be grilled; neither has been
tested. **That gate stays open**, and closing the ticket that holds it does not close it.

This ADR records the constraints that apply **if** a build ever happens. It is the version of the work
that survives if the answer to both questions turns out to be *"not yet"* — which is the likelier
answer, given a settled particle doctrine, an already-shipping typed client SDK, and an OpenAPI document
that answers the *discovery* half of what GraphQL is usually reached for.

Two things it explicitly leaves alone:

- **Substrate selection.** Downstream and nearly free once §1 is fixed; Lighthouse-vs-rebing is a driver
  choice, not an architecture decision.
- **The MCP alternative.** `splicewire/laravel-beam-mcp` is not a consumer of the particle declaration
  today — it is an independent `#[McpTool]` registry coupled only through the shared `AbilityResolver`.
  If the real goal is *"give agents a typed query surface over particle resources"*, that is a different
  project and is plausibly cheaper through MCP. Nothing here rules on it.

## Consequences

- **The wrong version of this project is now foreclosed cheaply.** Any future proposal that authors SDL,
  writes `Type` classes by hand, annotates a model, or generates per-verb CRUD mutations contradicts a
  recorded decision instead of merely being a matter of taste.
- **No dependency is added.** `webonyx/graphql-php` stays at 0 of 119 roots. This ADR is a constraint
  record, not a build step, and nothing in the estate changes because of it.
- **A fifth reader is priced, not assumed away.** The estate already has four readers of one declaration
  — REST `ParticleController`, Frame `ParticleFrameResourceHandler`, the operation transport, and
  codegen — and they disagree measurably. A resolver family would be reader five. That census is
  `particle-graphql-projection` ticket 02, which **remains open and is worth working regardless of this
  ADR**: it holds three latent defects, of which the sharpest is that REST's `filterable` index
  **discards the relative query**, live-but-dodged only because the one relative-mounted resource sets
  `filterable: false` specifically to avoid it and says so in-file. The next relative mount inherits it,
  and *a GraphQL nested field is a relative mount by another name* — so if this is ever built, that
  defect stops being latent.
- **The strongest argument for building anything at all is recorded here rather than lost**: a generated
  GraphQL schema is the cheapest instrument that measures whether one declaration means one thing,
  because it fails loudly exactly where four transports currently disagree quietly. That argument is
  real, and it is not sufficient on its own — it argues for the *census*, which is ticket 02, more
  directly than it argues for the transport.
- **Composite Schemas remains unusable.** Stage 0 after 27 months. Any federation argument that depends
  on it is depending on something that does not exist; Apollo Federation is the only production answer
  and carries its own vendor gravity, which Airbnb's Viaduct exists precisely to have rejected.
