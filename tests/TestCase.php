<?php

namespace Splicewire\Beam\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\JsonNs\Laravel\JsonNsServiceProvider;
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
            // The json-ns host bindings (ADR-0191/0192): the VocabularyRegistry/-Validator the
            // namespace-aware gates resolve, backed by SchemaRegistry when a test binds one.
            JsonNsServiceProvider::class,
            // The registry kernel's LARAVEL side (registry-kernel ticket 27). Not optional dressing:
            // `RegistryIndex` is a kernel-side TYPE but a Laravel-side singleton BINDING, so without this
            // provider `make(RegistryIndex::class)` auto-resolves a FRESH index on every call — measured
            // here, `$a === $b` was false. Anything describing into it or installing an authorizer would
            // write to an object nobody reads: green suite, empty index (registry-kernel ticket 40's
            // "04 D1 reached by a second route"). beam requires `rushing/laravel-popcorn`; the harness was
            // the only thing not booting it.
            PopcornServiceProvider::class,
        ];
    }

    /**
     * An in-memory cache store, so rate limiting works without a `cache` table.
     *
     * The public intake route is `throttle`d, and Laravel's ThrottleRequests resolves the RateLimiter
     * through the cache. Testbench's default store is the database one, whose `cache` table no
     * migration here creates — so every request through that route died with
     * `no such table: cache` and returned 500, taking the whole of PublicIntakeRouteTest with it (8
     * tests asserting 201/422/403/404 all saw 500).
     *
     * Nothing in this package tests cache PERSISTENCE, so an array store is the honest default: it
     * makes the throttle real (the limiter counts) without inventing schema the package does not own.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }
}
