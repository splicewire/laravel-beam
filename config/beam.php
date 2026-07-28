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

    // 'media'         => [ ... ]   // (ticket 08)
    // 'hooks'         => [ ... ]   // (webhook / sitemap / doctor registries)

];
