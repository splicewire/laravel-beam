# Registered Collections (ADR-0235)

A versioned JSON Schema opts in with `x-beam-collection: {label, nameField}`.
Both values are required; nameField is a declared property path. One `records`
resource serves every Collection; its required query address is
`collection=<absolute unversioned schema stem>`. `CollectionRoutes::mount()`
mounts REST within host-selected middleware. Frame uses its existing resource
routes. No resource registration is created per tenant schema.

The latest registered version supplies fields, acceptance, filter vocabulary and
sort. The address Data class owns required-parameter validation, shared by REST,
Frame and registry inspection. An unaddressed registry report describes structural
capabilities without evaluating tenant vocabulary. Backing construction does not
resolve schema services during provider boot.

- `x-beam-public-read: {fields: [...], where?: {all: [...]} | {any: [...]}}`.
  Rules use exactly ADR-0229 decision 12: `is set`, `is empty`, `equals`,
  `does not equal`, `greater than`, `less than`, `contains`. Each rule names a
  declared `field`, `operator`, and (where required) `value`. No keyword means
  no guest payload admission. An empty fields list grants no payload fields.
- `x-beam-unique: true` on a scalar property excludes the same record on update;
  missing/null is not a collision. Only Collections with unique properties take
  a schema-anchor row lock. Missing anchors refuse writes explicitly.
- `x-beam-relation: {collection: <unversioned stem>}` on string or string[] gives
  one or many same-realm record references. Expansion is one hop, batched per
  target stem after actor projection. A guest receives only the TARGET's Public
  Read. Missing/private/unmarked targets yield null or an empty list alike.
- `x-sort: {name, default?: boolean, direction?: asc|desc}` supports at most one
  default; otherwise newest first. Existing data-filters x-filter operators are
  reused. Unknown/malformed declarations refuse rather than silently degrading.

The normal host write policy remains mandatory; Public Read does not grant writes.
The Collection pipeline wraps shared acceptance failures as `fields` validation
errors, preserves authorize/validate/dedupe/persist/emit ordering, and serializes
unique writes on the record connection. Local standalone records only: shadows
are excluded by Beam. When beam-ux is present its contributor excludes entry
bodies, including trashed entries; without beam-ux there is no entry-body owner
whose foreign keys can be excluded.

Guest population rules currently use one PHP evaluator over chunked hydration,
then intersect the caller's query. This costs O(collection size), even for a small
page. The EP-34 receipt measures 1k/10k HTTP indexes; a tested SQL compiler is a
separate ticket-00 follow-up. Pending/failed transitions never become guest rows.
Tower registration enqueues a stem-scoped eager drain after commit, including old
current rows that have not yet been read. Its existing tenant/LLM gates remain.

Guest filter and sort names are limited to the declared Public Read field paths
(ADR-0230 d3), including when a filter has a different public alias. Hidden-field
probes return 422, the guest panel omits them, and a hidden default sort falls
back to the ordinary deterministic ordering. Signed-in vocabulary is unchanged.
Malformed stored relation values resolve as absent rather than failing a read.
