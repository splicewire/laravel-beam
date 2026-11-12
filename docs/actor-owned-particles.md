# Store a Data-declared resource in shared particle storage

Generate the resource and its input Data:

```sh
XDEBUG_MODE=off herd php artisan splicewire:beam:make:particle-resource SavedInput --key=saved-inputs
```

A writable resource without `--model` selects `ActorOwnedParticleBacking`. The generated declaration explicitly names `ActorOwnedParticleAccess::WRITE` and `ActorOwnedParticleAccess::class` as its write ability and read policy. Mount the printed `Particle::mount` line in the application's routes and fill in the read/input properties. No application model, table or migration is required; Beam's shared particle and schema tables must already be installed.

The backing stores input Data fields in `BeamParticle.payload`, preserving their wire names. Ordinary reads project the reconciled payload into the declared read Data and add the trusted id and timestamps. A custom projection can calculate application fields, such as `savedAt`, from the row. Do not add a repeated owner-stamping or payload-mapping hook. The owned path uses input Data's serialized business fields; an Eloquent `toModelAttributes()` mapping belongs to an explicitly model-backed resource.

Every query uses the declaration's schema binding, the current connection and the authenticated principal's morph type/id pair. The package stamps `meta.owner` on creation. Foreign schema/owner ids are absent, and guests cannot access this resource. The owner, schema and provenance envelope cannot be supplied through resource writes. A host's additional write gate still narrows the declared access policy.

By default the read Data class names the stable binding. Before renaming or moving a persisted Data class, declare an explicit `schemaRef`, for example `https://your-host.example/schemas/saved-inputs/1`, and plan the data migration. The input Data's generated business schema is registered through the existing schema registry; a different shape under the same version is refused by its write-once rule. Schema evolution uses a new version and the existing reconciliation/migration APIs. The write fails if the current target cannot be resolved.

PATCH input should use Spatie's `Optional` sentinel for an omitted field and nullable types only where clearing is allowed. The next complete payload is validated before persistence. Saved snapshots and explicit revision APIs retain their existing semantics: a content restore preserves the current owner and workflow authority, and cannot switch an owned row's schema binding. A plain save does not automatically mint a content snapshot or revision.

Use `--model=YourModel` for a dedicated relational model and its usual policy/mapping behavior. Read-only generators and explicitly selected backings keep their existing behavior. Actor-owned storage does not provide Public Read; `--public` requires an explicit model choice for a writable resource. Shared schema-authored Collections use their existing `x-beam-collection` declarations and remain a separate population: their standalone queries exclude principal-owned rows, and owned queries exclude unowned and shadow rows.

Workflow support is optional and belongs to the workflows package. Storing a particle does not create a lifecycle binding. Persisted-event names are currently catalog descriptions; this storage option does not add hook delivery or authorize schema-wide subscriptions through a global model policy.
