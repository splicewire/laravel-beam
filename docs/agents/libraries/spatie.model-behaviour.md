---
library:
  - spatie/laravel-activitylog
  - spatie/laravel-model-flags
  - spatie/laravel-model-status
  - spatie/laravel-schemaless-attributes
  - spatie/laravel-sluggable
tier: primary
arrives: declared
version-read:
  spatie/laravel-activitylog: 5.0.0
  spatie/laravel-model-flags: 1.5.0
  spatie/laravel-model-status: 1.20.0
  spatie/laravel-schemaless-attributes: 2.6.0
  spatie/laravel-sluggable: 4.0.3
docs: vendor/spatie/laravel-sluggable/docs — the only member with a vendored docs tree; the other four ship README.md + src/
config: none in beam; each package's own config key is the lever (`activitylog.table_name`, `model-status.status_model`, `model-flags.flag_model`, `sluggable.actions`)
related: [spatie.laravel-data, key-type.convention, migration-publish-ordering.convention]
date: 2026-08-27
---

# spatie model-behaviour cluster

**One doc, five packages, deliberately.** They are one concept — *a trait you add to an Eloquent
model to give it a behaviour, backed by its own table or column* — they are learned together, and
they fail together at the same seam (a shipped migration meeting beam's key-type and publish-order
rules). Five near-identical files would be five things to keep fresh instead of one. The convention
allows a doc per library; this is a grouping, not an exception to it.

## What it is here

Beam leans on this family for the behaviours it refuses to hand-roll onto every model: an audit
trail (`ActivityRecorder` / `RevisionRecorder` sit directly on activitylog), a status timeline
(ADR-0098 reads it), an open `meta` column, and a URL-facing name. Beam **extends** two of them —
`Splicewire\Beam\Models\HasStatuses` overrides spatie's scopes for uuid keys and
`Splicewire\Beam\Models\CentralActivityLog` subclasses spatie's `Activity` for the central
connection — and merely **requires** the other three so downstream packages and hosts inherit one
choice instead of picking their own. That last part is why `spatie/laravel-sluggable` is a *core*
requirement with **zero `HasSlug` uses in beam's own `src/`**: beam owns the estate's slug decision,
not a slug.

Four of the five ship a migration (schemaless-attributes ships only a cast), which makes this cluster
where a third-party table meets two house rules: `docs/agents/key-type.convention.md` and
`docs/agents/migration-publish-ordering.convention.md`. Read both before touching any table below.

## Concept index

A bare leaf resolves against its section heading's directory; anything else is written out in full.

### activitylog — `vendor/spatie/laravel-activitylog/`

| concept | what it's for | read |
| --- | --- | --- |
| `LogsActivity` | the subject side — a model that logs itself | `src/Models/Concerns/LogsActivity.php` |
| `CausesActivity` / `HasActivity` | causer side, and subject→entries relation | `src/Models/Concerns/CausesActivity.php`, `src/Models/Concerns/HasActivity.php` |
| `Activity` model | the row beam subclasses; resolves its own table from config | `src/Models/Activity.php` |
| `ActivityLogger` | the write path; resolves `activity_model` and instantiates it directly | `src/Support/ActivityLogger.php` |
| table + model levers | `table_name`, `activity_model` — the only supported renames | `config/activitylog.php` |
| upstream shape | spatie's own `$table->id()` migration, which beam re-shapes | `database/migrations/create_activity_log_table.php.stub` |
| v4 → v5 | `batch_uuid` dropped, `attribute_changes` added, PHP 8.4/L12 floor | `UPGRADING.md` |

### model-status — `vendor/spatie/laravel-model-status/`

| concept | what it's for | read |
| --- | --- | --- |
| `HasStatuses` | `setStatus`/`status()`/`currentStatus` scopes | `src/HasStatuses.php` |
| `Status` model | the `statuses` row; `status_model` binds a subclass | `src/Status.php`, `config/model-status.php` |
| `StatusUpdated` | the event a timeline listens on | `src/Events/StatusUpdated.php` |
| table | `statuses`, one shared stub estate-wide | `database/migrations/create_statuses_table.php.stub` |

### model-flags — `vendor/spatie/laravel-model-flags/`

| concept | what it's for | read |
| --- | --- | --- |
| `HasFlags` | named boolean marks with no schema change per flag | `src/Models/Concerns/HasFlags.php` |
| `Flag` model | `flag_model` binds a subclass | `src/Models/Flag.php`, `config/model-flags.php` |
| table | `flags` — note the migration is **not** a `.stub` | `database/migrations/create_flags_table.php` |

### schemaless-attributes — `vendor/spatie/laravel-schemaless-attributes/`

| concept | what it's for | read |
| --- | --- | --- |
| the cast | what beam puts on `meta` | `src/Casts/SchemalessAttributes.php` |
| the value object | a Collection subclass, **not** an array | `src/SchemalessAttributes.php` |
| `modelScope()` | querying into the JSON column | `src/SchemalessAttributesTrait.php` |
| column | no migration ships; the host adds its own `json` column | `README.md` |

### sluggable — `vendor/spatie/laravel-sluggable/docs/`

| concept | what it's for | read |
| --- | --- | --- |
| `HasSlug` + `SlugOptions` | the trait and its builder | `basic-usage/using-the-has-slug-trait.md` |
| `#[Sluggable]` attribute | the attribute form | `basic-usage/using-the-attribute.md` |
| generate from a callable | the reason spatie beat cviebrock here | `advanced-usage/source-fields.md` |
| uniqueness / duplicates | `allowDuplicateSlugs()`, per-parent uniqueness | `advanced-usage/uniqueness.md` |
| self-healing URLs | id-suffixed keys that survive a rename | `basic-usage/self-healing-urls.md` |
| overriding actions | v4's action seam — see trap 4 | `advanced-usage/overriding-actions.md` |
| the action bindings | the three keys a host must have loaded | `vendor/spatie/laravel-sluggable/config/sluggable.php` |

## House overlay

- **Use beam's `HasStatuses`, never spatie's.** `src/Models/HasStatuses.php` re-implements
  `scopeCurrentStatus` / `scopeOtherCurrentStatus` because spatie's `max(id)` subquery assumes an
  auto-increment key; under uuid it must order by `created_at`. Importing the vendor trait directly
  silently returns the wrong current status.
- **The central audit trail is `src/Models/CentralActivityLog.php`** — a connection pin, no `$table`
  pin. The pin is unavoidable: spatie's logger resolves `activitylog.activity_model` and
  instantiates it, so no parent connection is inherited (`1e4525f`).
- **Third-party tables use the package's own config lever where one exists**, and a subclass +
  `getTable()` only where it does not. `database/migrations/shared/create_activity_log_table.php.stub`
  reads `activitylog.table_name` so the model and the schema cannot disagree (`a5df9e1`).
- **`activity_log` keeps `$table->id()`** — spatie's shape, vendored, and that is correct under
  `docs/agents/key-type.convention.md`. Its **morph id columns are strings**, which is beam's
  deliberate widening (a bigint token id, a uuid user id and a string tenant slug are all subjects).
- **Slugs reach the API through `ParticleResource(routeKey: 'slug')`**
  (`src/Particle/Attributes/ParticleResource.php`), not a bespoke controller.
- **`meta` goes through `src/Concerns/HasMetaAttribute.php`**, which wires the cast, the
  `metaArray()` accessor and `scopeWithMeta()` in one place.

## Traps

**1. Two migrations can both claim `activity_log`, and the loser reports success.** `lunarphp/core`
ships a fixed-date `activity_log` with bigint `nullableMorphs`; under two bare existence guards
whichever sorts first owns the table. At `~/Herd/splicewire-app` the central schema landed Lunar's
bigint `subject_id` while every tenant schema was uuid, so `statusTimeline()`,
`latestStatusEvent()`, `isBusy()` and `provisioningIsStalled()` all threw
(`particle-contribution-seam` MAP §1258 and §624). The fix is the convergent guard plus
`splicewire:beam:install`'s table-ownership re-date — read
`docs/agents/migration-publish-ordering.convention.md`, and never hand-date a published copy.

**2. Which activitylog major is installed is a live question, not a settled one.** `^4.0|^5.0` spans
two, and beam's own lock now resolves **5.0.0** while the stub's docblock still argues for the 4.x
column set — v5 drops `batch_uuid` (`vendor/spatie/laravel-activitylog/UPGRADING.md`) and requires
PHP 8.4 / Laravel 12. The 4.x reasoning was itself earned: omitting `batch_uuid` on the belief that
"v5 dropped the batch system" produced `column "batch_uuid" ... does not exist` and four failing
tests (`a5df9e1`). Check what the *host* resolves before adding or removing a column.

**3. `SchemalessAttributes` is a Collection, not an array.** Passing `$model->getAttribute('meta')`
straight into a `?array` Data property fails to construct — four DTOs broke this way in
splicewire-ui, fixed with a dedicated cast + `#[WithCast]`
(`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/splicewire-ui/dto-refactor-verification/RESULTS.md`). Any particle projecting
a `meta` column hits this.

**4. Requiring a package in beam does not put it in the host.** beam requires
`spatie/laravel-sluggable` directly; a path-overlaid package's `composer.json` is the one edit that
is *not* live across roots, so **six roots were missing sluggable through beam** and 21 unsatisfied
requires were latent across 7 (`beam-facade` MAP §723). All latent — an unsatisfied require costs
nothing until a class loads, which is exactly why beam's zero `HasSlug` uses hid it. Related: v4
resolves its behaviour through `sluggable.actions`, so a test harness that boots without the
package's config dies on *"No action class is configured for key `generate_slug`"* — ~112 of tower's
failures (`voice-profile-and-prompt-steering` ticket 24).
