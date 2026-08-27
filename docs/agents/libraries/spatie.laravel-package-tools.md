---
library: spatie/laravel-package-tools
tier: primary
version-read: 1.93.1
arrives: declared
docs: vendor/spatie/laravel-package-tools/README.md
config: none — the library configures the provider, not the host
related: [migration-publish-ordering, key-type]
date: 2026-08-27
---

# spatie/laravel-package-tools

## What it is here

The skeleton `BeamServiceProvider` is built on. `src/BeamServiceProvider.php` extends
`PackageServiceProvider`, so beam's whole boot sequence is that class's fixed pass —
`register()` → `configurePackage()` → `packageRegistered()`, then `boot()` → the `Process*`
concerns → `packageBooted()` — and every binding, registry singleton, route macro, command and
audit beam ships hangs off one of those two hooks. `configurePackage()` itself stays tiny: a name,
`hasConfigFile([...])`, `hasMigrations([...])`, nothing else. **The load-bearing part is
`hasMigrations()`** — publishing stamps a timestamp at publish time, so this library's publish
mechanism decides the order beam's tables get created on a greenfield host, and beam has a written
convention about exactly that (see *House overlay*).

Declared at `^1.16`, resolving **1.93.1**. The package ships **no `docs/` tree** — the README is
the whole prose surface, and it is on disk and versioned with the code.

## Concept index

Paths below are a directory in the heading, a filename in the cell.

### The builder — `vendor/spatie/laravel-package-tools/src/Concerns/Package/`

What `configurePackage(Package $package)` can declare. Beam uses two; the rest exist.

| concept | what it's for | read |
| --- | --- | --- |
| **migrations** | `hasMigrations` / `discoversMigrations` / `runsMigrations` | `HasMigrations.php` |
| **config** | `hasConfigFile` — merge + publish, one entry per file | `HasConfigs.php` |
| commands | `hasCommands` — registered unconditionally at boot | `HasCommands.php` |
| views | `hasViews`, namespace + publish | `HasViews.php` |
| translations | `hasTranslations` | `HasTranslations.php` |
| routes | `hasRoute` / `hasRoutes` | `HasRoutes.php` |
| assets | `hasAssets` → `public/vendor/<short-name>` | `HasAssets.php` |
| install command | `hasInstallCommand(fn ($c) => …)` | `HasInstallCommand.php` |
| child providers | `publishesServiceProvider` | `HasServiceProviders.php` |
| view-layer extras | blade components, inertia, composers, shared data | `HasBladeComponents.php`, `HasInertia.php`, `HasViewComposers.php`, `HasViewSharedData.php` |

### The boot pass — `vendor/spatie/laravel-package-tools/src/Concerns/PackageServiceProvider/`

One `Process*` concern per builder slot — where a declaration becomes behaviour. Read
`ProcessMigrations.php` before touching anything about publishing.

| concept | what it's for | read |
| --- | --- | --- |
| **publish-time stamping** | `generateMigrationName()`, the `now()->addSecond()` loop, the directory-scoped stem glob | `ProcessMigrations.php` |
| config merge + publish | why a nested `beam/core` entry works | `ProcessConfigs.php` |
| the rest | assets, blade, commands, inertia, routes, translations, views | `ProcessAssets.php`, `ProcessBladeComponents.php`, `ProcessCommands.php`, `ProcessInertia.php`, `ProcessRoutes.php`, `ProcessTranslations.php`, `ProcessViews.php` |

### Core — `vendor/spatie/laravel-package-tools/src/`

| concept | what it's for | read |
| --- | --- | --- |
| **the lifecycle** | `registeringPackage` / `packageRegistered` / `bootingPackage` / `packageBooted`, and the fixed order between them | `PackageServiceProvider.php` |
| the package object | `name()`, and **`shortName()` — which strips the `laravel-` prefix and therefore names every publish tag** | `Package.php` |
| the generated installer | `startWith` / `endWith`, publish + migrate prompts; what a misdeclaration throws | `Commands/InstallCommand.php`, `Commands/Concerns/`, `Exceptions/InvalidPackage.php` |

Prose for all of the above: `vendor/spatie/laravel-package-tools/README.md` — §*Working with
migrations*, §*Config Files*, §*Creating an Install Command*, §*Lifecycle Hooks*.

## House overlay

**Migration publish ordering is a beam convention, and it is the reason this doc exists.**
Publishing stamps at publish time, so install order *is* migration order; a package shipping an
ALTER against another package's table must register a higher `$order` on its
`BeamInstallManifest` step. Full rule and the current tier table:
`docs/agents/migration-publish-ordering.convention.md`. Machinery:
`src/Install/BeamInstallManifest.php`. Enforcement: `src/Doctor/MigrationOrderingAudit.php`.

**Beam does not use `hasInstallCommand()`.** Its installer is its own —
`src/Console/BeamInstallCommand.php` driving the self-registration manifest, so every `beam-*`
package contributes publish tags + `migrates:` + `order:` from its own provider instead of core
learning consumer names. Supporting pieces live beside it in `src/Install/`
(`MigrationFiles.php`, `ConvergencePreflight.php`, `MigrationTravel.php`).

**Migrations are PUBLISH-ONLY stubs.** `runsMigrations` stays false; the files under
`database/migrations/shared/` carry a `.php.stub` extension so the framework migrator can never
load them in place. `hasMigrations([...])` is used deliberately over `discoversMigrations()` —
the latter's `->files()` is non-recursive and would miss the `shared/` subdir. Every entry is
declared with its `shared/` prefix, which is what routes the publish destination.

**Commands are registered in `packageBooted()`, not via `hasCommands()`** — several are guarded on
`runningInConsole()` or on an optional dev dependency, which the builder slot cannot express. Same
for the extra publish groups (`beam-stubs`, `beam-client-runtime`, `beam-scribe`): raw
`$this->publishes()`.

**Config is nested (`beam/core`, `beam/client`) plus one deliberate flat file** — read the note in
`src/BeamServiceProvider.php`'s `configurePackage()` before adding a fourth. A published table's
key types are separately governed by `docs/agents/key-type.convention.md`.

## Traps

**1. Publish-time stamps lose a filename race to fixed-date vendor migrations.** package-tools
stamps `now()`; `lunarphp/core` ships fixed `2026_01_01_*` copies of `users` and the spatie
permission tables. Both sides are `hasTable`-guarded, **so whichever sorts first wins and the
loser's guard reports success** — a fresh `~/Herd/splicewire-app` database silently got bigint
`roles.id` where dev has uuid, and `migrate:fresh` exited green. Sighted three times (Lunar's
`roles.id`; two `create_media_table`s; a stale `create_beam_submissions_table` fork in the
starter). `beam-facade` ticket 22 (closed), with the mechanism recorded in that map's ledger. The
fix in tree is the `0001_01_01_*` stub prefix — see `create_activity_log_table.php.stub`'s
docblock.

**2. A publish tag is not a name you choose; it is a side effect of a registration.**
package-tools mints `<short-name>-migrations` *inside* its loop over `hasMigrations` entries, so
deleting the last migration deletes the tag — and `shortName()` strips `laravel-`, making beam's
tags `beam-config` / `beam-migrations`, never `laravel-beam-*`. Listing a tag that no longer
exists moves dead-artifact rot from disk into the install manifest. `beam-facade` ticket 77
(closed 2026-08-23).

**3. Editing a create-stub reaches nothing already migrated, and the published copy then drifts
unseen.** A stub edit plus `migrate` at an installed host reports `Nothing to migrate` — the
published copy is a snapshot taken at install. `PublishedMigrationDriftAudit` was built for this
and found **314 drifted copies across 20 hosts on its first run, 119 at the flagship alone**
against a ticket estimate of "six". `beam-facade` tickets 86 and 116 (both closed 2026-08-24/26).

**4. Re-stamping one published file moves it relative to every other already-published file.**
Re-dating is not local. If a dependency chain must be regenerated, delete and re-publish the
**whole** chain so it re-stamps together — the partial move already produced a duplicate-column
failure on a clean migrate. Cited: `docs/agents/migration-publish-ordering.convention.md`.
