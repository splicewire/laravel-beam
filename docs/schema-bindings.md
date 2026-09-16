# Resource schema bindings

`schemaRef` on `#[ParticleResource]` or the runtime `ParticleResource` explicitly
binds a resource's read Data class to a schema identity. Declare it where the
resource is declared; no second registration is needed.

For example, a resource keyed `articles` can declare
`schemaRef: 'https://example.test/schemas/content/article/1'`. Beam compares
schema stems through `SchemaId`, so lookups for that stem or another version
resolve the same read Data. Schema version selection and payload migration
remain the target resolver's responsibility. Resource keys and schema identities
remain separate grammars; no slug guessing or authority stripping occurs.

`SchemaBindingIndex::dataClassFor($identity)` returns the declared Data class or
null. The index is derived from `ParticleResourceRegistry`, includes headless
resources, and retains its live authorization filter. It has no registration
method or independent cache, so late registrations and same-key replacements
are visible immediately.

An explicit binding requires a non-empty schema identity and read Data class.
Two resource keys claiming the same explicit stem throw before registration
mutates anything, even if their Data classes are identical. Replacing the same
resource key releases its previous claim. Registration checks the unfiltered
declarations: actor visibility cannot hide a collision.

Without `schemaRef`, the read Data class-string itself is the binding. Multiple
resources may share that implicit identity: it already names exactly one Data
class, and realm-specific resources legitimately reuse it. A resource with no
read Data and no explicit schema claim is absent from this index. The optional
binding neither enables writes nor changes permissions, realms, or Frame fields.
