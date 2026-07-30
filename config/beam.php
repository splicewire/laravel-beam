<?php

use Splicewire\Beam\Models\SchemaRecord;

return [

    /*
    |--------------------------------------------------------------------------
    | beam substrate
    |--------------------------------------------------------------------------
    | The app-substrate rung. This config is intentionally near-empty at mint:
    | beam boots headless and the leaf-extraction tickets (07-10) populate it as
    | the generic schema-record / media traits and host-hook registries land.
    |
    | beam depends on nothing above it — frame (the editor rung) depends on beam,
    | never the reverse (ADR-0082). Keep host/editor concerns out of this file.
    */

    /*
    | Swappable models (Spatie swappable-model pattern). A host that composes the beam
    | traits on its own record model points this at its subclass.
    |
    | The generic BeamSubmission REFERENCE model was retired (ADR-0138): a submission is
    | exactly one thing — a FormSubmission (a beam SchemaRecord). The two-model split was
    | created-but-never-read; the FC-15 reference precedent is reversed.
    */
    'models' => [
        'schema_record' => SchemaRecord::class,
    ],

    /*
    | Table names. "shared" means shared CODE, not a shared database — every app that
    | consumes beam gets its own tables. The migrations are publish-only stubs; a multi-tenant
    | host owns tenant-guarded copies so records land in the tenant schema, not central.
    */
    'tables' => [
        'schema_records' => 'schema_records',
    ],

    /*
    | The optional generic PUBLIC INTAKE door (beam-write-pipeline ticket 04 / ADR-0150). A host mounts
    | this to accept anonymous form submissions with no controller of its own — or leaves it OFF and
    | calls RecordWriter from its own controller. It is deny-by-default: nothing is anonymously writable
    | unless a schema stem is explicitly listed in `public_schemas`.
    */
    'intake' => [
        // Mount POST /beam/intake/{form}. Off by default — the door is opt-in.
        'enabled' => false,

        // URL-safe form slug => the schema stem (or absolute $id) it resolves. The route addresses a
        // form by its slug; the slug maps to a registered schema. A slug absent here (and not itself a
        // resolvable stem) is a 404. Being addressable is NOT being public — see `public_schemas`.
        'forms' => [],

        // The allow-list: schema stems (or versioned $ids) a stranger may submit. Empty ⇒ nothing is
        // publicly submittable (the safe default; a bad default here would silently open write access).
        // A form that resolves but is absent here is refused (403) by the deny-default gate.
        'public_schemas' => [],

        // Opt-in honeypot bot defence, default OFF — never silently imposed.
        'honeypot' => [
            'enabled' => false,
            'field' => 'website',
        ],

        // Route throttle, "{maxAttempts},{decayMinutes}".
        'throttle' => '5,1',
    ],

    // 'media'         => [ ... ]   // (ticket 08)
    // 'hooks'         => [ ... ]   // (webhook / sitemap / doctor registries)

];
