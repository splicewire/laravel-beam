# ADR-0235 — A registered schema types a Record's fields and never declares a resource

**Status:** accepted — decided in editor-parity ticket 18, 2026-10-16, under the owner's standing instruction of that day
to accept every recommended answer. There was no live exchange; the ticket records each question, its options and the
answer taken.
**Date:** 2026-10-16
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/editor-parity/` ticket 18 ("A registered schema as a
Collection").
**Builds on:** the particle doctrine (`docs/agents/particle-doctrine.md`), ADR-0212 (the `backing:` slot), ADR-0219 (a
backing declares its filter vocabulary), ADR-0230 (a guest reads a resource only through its Public Read).
**Amends:** ADR-0219 decision 3. **Extends:** ADR-0230 (a registered Collection declares its Public Read in its schema).
**Companions:** `splicewire/laravel-beam-ux docs/adr/0238-a-hosted-site-binds-the-registered-schemas-its-tenant-marks-and-follows-their-declared-renames.md`
(the editor side), `splicewire/splicewire-app docs/adr/0138-beam-rescoped-to-schema-driven-cms-core.md` (a schema-typed,
versioned, migrate-on-read particle), `splicewire/tower docs/adr/0002-a-tenant-registered-schema-id-is-stemmed-on-the-origin-the-registration-arrived-on.md`.

## Context

A tenant admin registers versioned JSON Schemas at runtime, with no deploy (`POST beam/schemas`, `splicewire/tower`
`src/Api/V1/SchemaRegistryController.php`). A version is write-once. A particle write validates its payload against its
type's latest registered version (`src/Write/Stages/ValidateStage.php:31-33`, `src/Schema/RegistrySchemaTargetResolver.php:39-47`),
and a read migrates a lagging payload with cheap rungs, leaving a row that needs an expensive rung `pending` on its
original payload (`rushing/laravel-versioning` `src/Concerns/ReconcilesPayloadOnRead.php:16-27`). Nothing serves the
records of one schema as a set: no resource is backed by the particles table, and Frame never refers to schema-typed
particles (editor-parity ticket 12).

The particle doctrine says every boundary-crossing shape is a declared Data class, at one of three sites, with no fourth.
A registered schema is a JSON document a tenant writes at runtime, so no generator can project it at build time. Two
declared Data classes already carry a member whose shape is a JSON Schema the platform stores: the `schemas` resource's
`artifact` (`src/Data/BeamSchemaData.php:99-101`, "The artifact IS the schema"), and a theme entry's form, rendered from
the schema the entry resolves (`splicewire/laravel-beam-ux` `src/Particle/EntryBodyEnvelope.php:111-131`).

Measured 2026-10-16 at the flagship's local database, per tenant, each tenant in its own try/catch (18 tenants, 0
failures):

- 69 registered rows sit in 11 tenants. 68 are under the central authority: six `content-schema/` synthesis contracts
  and intake shapes seeded into 10 tenants, and eight `food-safety/` and `merchant-compliance/` intake shapes. One is
  stemmed on a tenant's own origin: `https://audiostud.app.splicewire.test/schemas/audiostud/song/2`, a generation spine.
  None carries `x-filter` or `x-sort`.
- No tenant holds a record of any of them: every tenant's particles and submissions tables hold 0 rows. The central
  particles table holds 77 rows, none with a `schema_ref`, 43 of them entry bodies.

Four mechanisms a set of records needs are missing or don't fit:

- **Filters.** `x-filter` and `x-sort` are `rushing/laravel-data-filters`' keywords, emitted from `#[Filterable]` and
  `#[Sortable]`. `x-sort` carries only a name, though `#[Sortable]` declares a default and a direction
  (`src/Schema/FilterableAttributesStrategy.php:23-31`, `src/Attributes/Sortable.php:14-18`). ADR-0219 decision 3 lets a
  backing declare its vocabulary only when it doesn't query.
- **The drain.** Registering a version enqueues a drain over Composition's three models only (`splicewire/tower`
  `src/Jobs/MigrateInstancesJob.php:43-47`). Nothing drains the particles table.
- **Uniqueness.** Beam owns one schema keyword, `x-beam-dedupe` (`src/Schema/Keywords.php:29,37`). It is a capture
  ledger's verdict, and it compares a key against every row, the row being saved included
  (`src/Write/Stages/DedupeStage.php:84-90`, `:121-128`), so it can't say a field is unique.
- **Relations.** JSON Schema's `$ref` composes schemas. It doesn't relate one record to another.

## Decision

1. **A registered schema never declares a resource.** It may type one member of a Data class that is declared at one of
   the doctrine's three sites: the field values of a Record, and, under `splicewire/laravel-beam-ux` ADR-0238, a page's
   Page Fields on a Hosted site. The request names the schema. Every write validates the member against the schema's
   latest version, and every read serves the member beside the schema. The member's generated type is an open object,
   and a client renders it from the JSON Schema it is sent. A registered schema binds to no Data class of its own, so
   `SchemaBindingIndex` is unchanged.
2. **A Collection is a registered schema its author marks.** The version carries `x-beam-collection`, with the
   Collection's label and the field that names a Record. Only a marked schema's Records are served. Forms, synthesis
   contracts and generation spines stay unmarked.
3. **One resource serves every Collection's Records.** It is declared once, by attribute, and its backing is a
   `ResourceBacking` class-string, resolved per request (`src/Particle/Backing/BackingResolver.php:43-50`). Its address
   carries the Collection's Schema Stem, without a version, so a reader follows the Collection across versions.
   - **Its Records** are the standalone, local particles whose `schema_ref` has that stem. An entry's body is never a
     Record, and neither is a shadow (`source_tier`, flagship ADR-0161). Submissions stay a capture ledger, and
     Composition instances stay generation working state.
   - **Its capabilities.** It queries, resolves, writes, and declares its vocabulary (decision 4).
4. **A Collection's fields, vocabulary and default sort come from its latest version.**
   - **Fields** are the version's properties, with paths into nested objects (`splicewire/laravel-beam-ux` ADR-0229
     decision 3).
   - **The vocabulary** is the `x-filter` and `x-sort` on those properties. The backing declares it
     (`DeclaresFilterVocabulary`), and the filter panel, saved filters and a Repeat's saved query are checked against
     that declaration.
   - **The default sort** is the property whose `x-sort` carries `default`, in its `direction`. `x-sort` gains both
     members in `rushing/laravel-data-filters`, which emits them from `#[Sortable(default:, direction:)]` too. With none,
     Records sort newest first.

   This amends ADR-0219 decision 3: a backing whose rows have no filter Data class declares its vocabulary, even though
   it queries.
5. **A Collection declares its own Public Read, in its schema.** `x-beam-public-read` names the fields a guest may
   receive and, optionally, a rule a Record must match, written with ADR-0229 decision 12's operators. The resource
   declares one Public Read in code (`publicRead:`, ADR-0230 decision 1), whose scope and fields read the Collection's
   declaration.
   - **No keyword, no guest read.** A Collection that declares none gives a guest nothing (ADR-0230 decision 2).
   - **A Record not yet migrated** (`pending` or `failed`) is never sent to a guest.
   - **The owner.** The author who registers the schema is the resource owner ADR-0230 means. A site author binds the
     Collection and can only narrow what it gives.
6. **A field can be declared unique.** `x-beam-unique` on a property makes the write path refuse a second Record with the
   same value in that Collection. `x-beam-dedupe`'s `reject` mode doesn't serve: it would refuse a Record's own unchanged
   update.
7. **A field can relate to another Collection.** `x-beam-relation` on a property names another Collection of the same
   realm, and its value is a Record id or a list of them. Includes and nested Repeats walk it, loaded in one batch
   (ADR-0229 decision 7). A guest reads the related Records only through the target's Public Read (ADR-0230 decision 3).
8. **Registering a version drains its Collection's Records,** so they migrate without waiting to be read.

## Consequences

- **ADR-0219 decision 3 is amended.** `ResourceRegistryReport` and `particle.capability-disagreement` keep reporting a
  queryable backing that declares a vocabulary beside a filter Data class or a data-filters registration, and stop
  reporting one that has neither. Saved-filter validation reads the declared vocabulary when a resource has no filter
  Data class.
- **The particle doctrine gains a paragraph** under its invariant, citing this ADR.
- **Beam owns five schema keywords:** `x-beam-dedupe`, and now `x-beam-collection`, `x-beam-public-read`, `x-beam-unique`
  and `x-beam-relation`. Each is read from the latest version, and each is loud when malformed, as `x-beam-dedupe` is.
- **Frame learns a resource whose edit form depends on the address.** Frame's schema endpoint reflects one Data class per
  resource today (`schemastud/laravel-frame` `src/Http/Controllers/FrameResourceController.php:94-140`). For this
  resource it serves the Collection's latest schema inside the Record envelope.
- **The drain covers the particles table.** Tower's `MigrateInstancesJob` drains Composition's three models today, and
  the System page's Migrate button sends a schema stem that the job takes as a model class. That is a defect, routed to
  launch ticket 00.
- **Dropping a field removes its values** from every Record as the Record migrates (data-schemas' structural rung). An
  added field takes its default, or a typed empty value.
- **A schema's field names are public** at its `$id` once the tenant tier is served (`splicewire/tower` ADR-0002). A
  Public Read hides values, not names.
- **The doctor's count** of resources reachable without an authenticating middleware and with no Public Read (ADR-0230)
  sees this resource's declared Public Read. A Collection that declares none is the editor's warning (ADR-0229 decision
  10).
- **Scaffold Packs carry no tenant-authored schema** (`splicewire/tower` `src/Support/ScaffoldPackContentServiceProvider.php:43-55`).
  How a starter site carries its Collections is editor-parity ticket 20.
  *Answered 2026-10-16 by `splicewire/tower docs/adr/0009-a-starter-sites-schemas-travel-re-minted-on-each-new-tenants-own-origin-with-their-sample-records.md`:
  a pack carries the registered schemas a starter site's pages name, re-minted on the new tenant's own origin, and the
  Records of the Collections among them.*

## Considered and not taken

- **A registered schema declares a resource:** one resource per Collection, registered at request time from the tenant's
  registry. It is the fourth declaration site the doctrine forbids. It needs a per-tenant freeze point that the
  attribute, boot and per-request backing don't offer, and it would put tenant state in a registry bound once per process
  (`src/BeamServiceProvider.php:683`).
- **A relative mount under the `schemas` resource.** That resource's subject is one version's row
  (`src/Data/BeamSchemaData.php:76-106`), and a Collection is a stem.
- **Every registered schema as a Collection.** The binding picker would offer intake forms and generation contracts.
- **Records drawn from every schema-typed table**, submissions and Composition instances included. A submission is an
  immutable capture (flagship ADR-0138), and publishing one needs moderation that nothing here designs.
- **A Public Read kept as a Collection setting outside the schema.** A Collection would have two declaring homes.
- **One fixed Public Read for every marked Collection.** It would publish every field an author ever adds.
- **Every top-level scalar field filterable and sortable by default.** A filter on a field the author never meant to
  expose answers questions about its values.
- **`x-beam-dedupe`'s `reject` as uniqueness.** It refuses a Record's own update.
