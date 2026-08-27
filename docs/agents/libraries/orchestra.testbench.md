---
library: orchestra/testbench
tier: primary
version-read: v11.1.0
arrives: declared
docs: https://packages.tools/testbench
machinery: orchestra/testbench-core v11.3.6
related: [test-runner.convention]
date: 2026-08-27
---

# orchestra/testbench

## What it is here

Beam has no `bootstrap/app.php` of its own, so testbench is **the only reason there is a Laravel
application to test against at all**. Every test in beam's suite (1,445 methods) inherits
`tests/TestCase.php`, which extends `Orchestra\Testbench\TestCase` and declares the seven service
providers that constitute beam's universe — and that list is itself an assertion: beam boots with
its own provider and no frame rung (ADR-0082), which `tests/BeamBootTest.php` guards. The harness is
where beam's layering law, its schema-authority tri-state, and its container bindings are proved, so
a change to `tests/TestCase.php` is a change to what the whole suite means.

Declared `^9.0|^10.0|^11.0` (require-dev), resolving **v11.1.0**. Three majors, one harness.

**`orchestra/testbench` is a metapackage** — its install directory holds only
`vendor/orchestra/testbench/composer.json`. Every class below lives in `testbench-core`. Look there.

## Concept index

Directory in the heading, filename in the cell.

### The base class — `vendor/orchestra/testbench-core/src/`

| concept | what it's for | read |
| --- | --- | --- |
| `TestCase` | the abstract class beam's `tests/TestCase.php` extends | `TestCase.php` |
| `functions.php` | `default_skeleton_path()`, `package_path()` and friends | `functions.php` |
| CLI harness | run an artisan command against the skeleton | `vendor/orchestra/testbench-core/testbench` |

### Application hooks — `vendor/orchestra/testbench-core/src/Concerns/`

The whole contract an agent overrides. Read this directory before adding a hook by guesswork.

| concept | what it's for | read |
| --- | --- | --- |
| **`getPackageProviders()` / `getPackageAliases()`** | the app's entire provider universe; nothing is auto-discovered | `CreatesApplication.php` |
| **`defineEnvironment()` / `getEnvironmentSetUp()`** | config mutation, run *after* providers register | `CreatesApplication.php` |
| `ignorePackageDiscoveriesFrom()` | suppressing a discovered provider | `CreatesApplication.php` |
| **`defineDatabaseMigrations()`** | the sanctioned place to run migrations per test class | `HandlesDatabases.php` |
| in-memory detection | `usesSqliteInMemoryDatabaseConnection()` | `HandlesDatabases.php` |
| `defineRoutes()` / `defineWebRoutes()` | mounting routes a package test needs | `HandlesRoutes.php` |
| `loadMigrationsFrom()` | pointing the harness at a migration path | `InteractsWithMigrations.php` |
| `loadLaravelMigrations()` | pulling in framework migrations (users, cache, jobs) | `WithLaravelMigrations.php` |
| published-file assertions | `assertFileContains`, and cleanup after a real `vendor:publish` | `InteractsWithPublishedFiles.php` |
| workbench | the optional real app skeleton in the package repo — **beam does not use it** | `InteractsWithWorkbench.php`, `WithWorkbench.php` |
| lifecycle order | where every hook above is actually called | `Testing.php`, `ApplicationTestingHooks.php` |
| attribute dispatch | how the `#[Define*]` attributes reach the hooks | `HandlesAttributes.php` |
| Pest bridge | what a Pest bootstrap file would bind to | `InteractsWithPest.php` |
| sqlite file databases | when `:memory:` is not enough | `vendor/orchestra/testbench-core/src/Concerns/Database/WithSqlite.php` |

### Per-test attributes — `vendor/orchestra/testbench-core/src/Attributes/`

The per-method alternative to overriding a hook for the whole class.

| concept | what it's for | read |
| --- | --- | --- |
| `#[DefineEnvironment]` | config for one test, not the class | `DefineEnvironment.php` |
| `#[DefineDatabase]` / `#[DefineRoute]` | same, for migrations and routes | `DefineDatabase.php`, `DefineRoute.php` |
| `#[WithConfig]` / `#[WithEnv]` | a single key or env var | `WithConfig.php`, `WithEnv.php` |
| `#[WithMigration]` | opt into framework migration groups | `WithMigration.php` |
| `#[RequiresDatabase]` / `#[RequiresLaravel]` | skip conditions | `RequiresDatabase.php`, `RequiresLaravel.php` |

### Boot order — `vendor/orchestra/testbench-core/src/Bootstrap/`

| concept | what it's for | read |
| --- | --- | --- |
| **the `:memory:` default** | forces `database.connections.testing` to in-memory sqlite | `LoadConfiguration.php` |
| provider registration | when `getPackageProviders()` is consumed | `RegisterProviders.php` |
| env loading | `.env` resolution against the skeleton | `LoadEnvironmentVariables.php` |

### Skeleton and internals — `vendor/orchestra/testbench-core/src/Foundation/`

| concept | what it's for | read |
| --- | --- | --- |
| the application | what `resolveApplication()` builds | `Application.php` |
| the yaml-config reader | reads a project-root `testbench.yaml`, which beam does **not** ship | `Config.php` |
| package manifest | discovery, and its cache | `PackageManifest.php` |
| the skeleton app on disk | `base_path()` in a package test resolves here | `vendor/orchestra/testbench-core/laravel/` |
| default DB config | the `sqlite`/`mysql`/`pgsql` connections a test inherits | `vendor/orchestra/testbench-core/laravel/config/database.php` |
| **the cached package manifest** | stale file that hides a correctly-installed provider | `vendor/orchestra/testbench-core/laravel/bootstrap/cache/packages.php` |
| parallel runner | per-worker database handling under `--parallel` | `vendor/orchestra/testbench-core/src/Features/ParallelRunner.php` |

## House overlay

**PHPUnit, not Pest — deliberately, and audited.** The family convention is Pest
(`docs/agents/test-runner.convention.md`); beam core is one of the thirteen PHPUnit holdouts and
ships `src/Doctor/TestRunnerConformanceAudit.php`, an advisory audit **it fails itself**. There is no
`tests/Pest.php` here; `phpunit.xml` and composer's `test` → `vendor/bin/phpunit` are the entry point.

**No `defineDatabaseMigrations()` anywhere in this suite.** Beam's migrations are publishable stubs,
not suite fixtures — tests that need a table `Schema::create()` it themselves in `setUp()`
(`tests/Write/ParticleWriterTest.php`, `tests/RecordsRevisionsTest.php`). Publishing is exercised in
exactly one place, `tests/Install/BeamInstallTest.php`, which drives a real `vendor:publish`.

**No workbench, no `testbench.yaml`.** The skeleton is the vendored one, shared by every package on
this machine — which is trap 4.

**`splicewire:beam:dev:isolated-test-db` is not needed here.** The suite runs sqlite `:memory:` per
`vendor/orchestra/testbench-core/src/Bootstrap/LoadConfiguration.php`, so there is no shared test
database a neighbouring session can yank (measured: `laravel-beam-tenancy/particle-identity-resources`
ticket 01). The fleet warning in the ecosystem `AGENTS.md` applies to hosts, not to this package.

## Traps

**1. `getPackageProviders()` is the entire universe, and omissions pass vacuously.** The container
happily auto-resolves a concrete class nobody bound, so a test asserting against an unregistered
singleton gets a fresh instance and goes green. `registry-kernel` ticket 27 D3 found beam's own
harness not booting `PopcornServiceProvider`: `make(RegistryIndex::class)` returned a *new* index per
call (`$a === $b` false), so every `describe()` landed on a throwaway — green suite, empty index.
Fixed in `32c688e`. Ticket 37's standing instruction: **check `getPackageProviders()` before
believing any index assertion.** Requiring the package in `composer.json` does not fix it; testbench
does not auto-discover.

**2. Array order is register order, and `defineEnvironment()` runs after all of it.**
`migration-classification-remediation` ticket 10 had to call `configurePackage()` directly because
`defineEnvironment()` fires *after* providers register — a register-time config read cannot be
tested through it. `particle-contribution-seam` ticket 18 §A2 records both halves biting at once in
tower's harness: a container binding lost to last-bind-wins (a later provider's `register()` rebound
the interface to its Null default) *and* a config sub-key wiped by a wholesale `config()->set()` in
`defineEnvironment()`. Prefer `#[DefineEnvironment]`, which the sweep brief (`registry-kernel`
`assets/15-sweep-brief.md` §3) endorses as "what a real host's config does anyway."

**3. A `defineEnvironment()` in the base `TestCase` is a claim made by every test in the suite.**
`beam-facade` ticket 85: setting `data-schemas.base_uri` suite-wide also **mounted a public schema
route** into every test, and 28 bare-app assertions (`UndeclaredSurfaceAudit`,
`OpenApiSpecCorroborator`) failed on the surprise route. Hence `schemaAuthority()` in
`tests/TestCase.php` — a per-class opt-in returning `null` by default. Same ticket, the other
direction: 41 tests were dark because nothing declared an authority at all.

**4. The skeleton is real, on disk, and shared across runs.** `86a5e27`: install tests writing a
real `vendor:publish` into `vendor/orchestra/testbench-core/laravel/` left artifacts that persisted
across tests *and* runs, shadowing package config defaults and accumulating duplicate creates
("media already exists"). **~330 leaked files were purged**; `tests/Install/BeamInstallTest.php` now
sweeps in `tearDown()`. The related cache bite (`laravel-surgeon/gate-reachability` ticket 01): a
stale `vendor/orchestra/testbench-core/laravel/bootstrap/cache/packages.php` made a correctly-installed provider invisible to
`testbench list` — "the nastiest of the three: it looks exactly like a failed install."

**5. Testbench's defaults are a bare app, not a host.** Its default cache store is the database one
and no migration here creates a `cache` table, so every request through beam's throttled public
intake route died `no such table: cache` and returned 500 — eight `PublicIntakeRouteTest` assertions
at once (`b42c1e6`; the reasoning is preserved on `defineEnvironment()` in `tests/TestCase.php`).
When a beam route 500s under test, suspect a missing skeleton default before suspecting beam.
