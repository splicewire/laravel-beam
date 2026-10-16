# Registered Collections (EP-34)

A versioned registered schema is a Collection only when it carries
`x-beam-collection: {label, nameField}`. `nameField` is a declared property dot-path.
One code-declared `records` resource serves all Collections. Every operation carries
its absolute, unversioned Schema Stem in the required `collection` query parameter;
that address is not a filter. No per-schema resource registrations are created.

`x-beam-public-read` is `{fields: [dot-paths], where?: {all: [rules]} | {any: [rules]}}`.
A rule is `{field, operator, value?}`. The exact operators from beam-ux ADR-0229
 decision 12 are `is set`, `is empty`, `equals`, `does not equal`, `greater than`,
`less than`, and `contains`. No aliases. Set/empty rules omit value; other rules
require it. Only declared properties can be named. Predicate fields need not be
returned. Without Public Read a guest receives no records; pending/failed migration
records are never guest-readable. A relation applies the target's Public Read too.

`x-beam-unique: true` marks a scalar field. Missing/null does not collide; a record's
unchanged self-update is allowed. Uniqueness is within one Collection and realm.
`x-beam-relation: {collection: <absolute unversioned stem>}` marks a string id or
array of string ids; the schema type determines cardinality. Targets must be
Collections in the same realm. JSON Schema `$ref` remains structural composition.

`x-filter` retains data-filters' existing dialect. `x-sort` retains `name` and adds
`default` (boolean) and `direction` (`asc` or `desc`). At most one field is default;
with none, records sort newest first. Unknown/malformed keyword members, unknown
property paths, unsupported operators and multiple defaults are errors.

Approved by orch-lead 2026-10-16 20:28Z against ADR-0235 decisions 1–8.

The standalone query always excludes shadow tiers. Optional owning packages tag a
`StandaloneRecordExclusion` contributor; its query is intersected with the local
population. Beam UX contributes every entry `particle_id`, including trashed owners.
Without Beam UX installed there is no entry-body ownership contributor; Beam does
not depend on, inspect, or guess an optional package's tables.
