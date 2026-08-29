<?php

namespace Splicewire\Beam\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
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
     * The one fixture schema authority this suite mints and asserts `$id`s under.
     *
     * Deliberately NOT `https://schemas.splicewire.app`, which is what every `$id` literal in
     * these tests said before ticket 85: that string is the package default ticket 64 deleted — an
     * unregistered domain, and one vendor's brand shipped from a schemastud package. Leaving it in
     * the family's reference suite re-teaches the value the estate removed.
     *
     * @see self::schemaAuthority() for why it is path-shaped and why declaring it is per class
     */
    public const SCHEMA_AUTHORITY = 'https://beam.test/schemas';

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
            // ⚠️ The SEVENTH recorded instance of the testbench trap, and the one that makes beam's own
            // `filterable: true` promise testable. `Rushing\DataFilters\Registry\ResourceRegistry` is
            // auto-resolvable, so without this provider `app()` mints a FRESH registry per call: beam's
            // boot-time `declareFilterResources()` writes into a throwaway and every read sees an empty
            // registry — green suite, no registrations, no error. It also binds `DataFilterManager`
            // (what `DataFilter::query()` resolves through) and the `data-filters` config the reflector
            // reads. `rushing/laravel-data-filters` is a hard `require` of beam, not a dev extra; the
            // harness was the only thing not booting it.
            DataFiltersServiceProvider::class,
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

        $app['config']->set('data-schemas.base_uri', $this->schemaAuthority());
    }

    /**
     * The schema authority THIS test's app declares — `data-schemas.base_uri`'s tri-state, opted
     * into per test class (beam-facade ticket 85).
     *
     * `schemastud/laravel-data-schemas` ships no default (ticket 64: the authority is the origin
     * that SERVES the schema, and an `$id` is write-once, so an undecided authority throws rather
     * than falls back). Nothing here declared one, so every test driving a `SchemaIdentity`
     * fixture died on `MissingSchemaBaseUri` — 41 of them, across the write pipeline, the
     * record-versioning seam and the public intake door.
     *
     * DECLARING IS PER CLASS, NOT SUITE-WIDE, because ticket 82 made `base_uri` one knob with two
     * effects: it mints versioned `$id`s AND mounts the public schema door. Setting it globally
     * mounted a route into all 1057 tests, which is a strictly larger claim than the suite makes —
     * `UndeclaredSurfaceAudit` and `OpenApiSpecCorroborator` both assert against a bare app whose
     * route table is only what the test itself mounts, and measured, 28 tests failed on the
     * surprise route. So the default stays `null` (undecided, mounts nothing, throws if a
     * versioned fixture is generated) and the classes that simulate a schema-serving host say so.
     *
     * The value is PATH-shaped, which is not decoration. A bare-origin authority mounts the door
     * at `{path}` — a root catch-all — and Laravel's `RouteCollection` is keyed by method+URI, so
     * a second root catch-all silently REPLACES it. That is not hypothetical: it is why
     * `~/Herd/splicewire`'s door does not exist (see the ticket). Every other declaring host in
     * the estate is path-shaped; the fixture authority matches them.
     *
     * @return string|bool|null a URI to mint versioned `$id`s, `false` to opt out, `null` to leave
     *                          the authority undecided
     */
    protected function schemaAuthority(): string|bool|null
    {
        return null;
    }
}
