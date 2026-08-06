<?php

namespace Splicewire\Beam\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\BeamServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam boots with ONLY its own provider — no frame, no editor rung. If this
     * list ever needs the frame provider to make beam work, the layering has
     * inverted (ADR-0082) and the test should fail loudly.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // beam's own provider. NOT the frame/editor rung — the layering law
            // (ADR-0082) is that beam boots without frame; BeamBootTest asserts it.
            BeamServiceProvider::class,
            // Declared dependencies of beam-core: the revision trait is activitylog-backed and
            // its RevisionEntry projection is a spatie/laravel-data object.
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            // The two open foundations beam-core composes (ADR-0135): the versioning seam
            // (Migrator/RecordReconciler/Versionable + VersionStore) and the schema engine
            // (SchemaRegistry the default reconciler resolves). Declared dependencies DOWN,
            // not rungs above beam.
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            // The entitlement kernel seam (Frame OS ticket 07/08): binds the kernel EntitlementResolver
            // (NullEntitlementResolver by default). beam is the authority that registers the entitlement
            // Gate abilities + can-map/manifest projection over this seam. A declared dependency DOWN
            // (beam → permission-cascade), not a rung above beam.
            PermissionCascadeServiceProvider::class,
        ];
    }
}
