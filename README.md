# laravel-beam

The **app-substrate** rung of the schemastud stack — the runtime an application
stands on **with or without an editor**.

```
seam  ←  frame  ←  splice        (editor / UI tooling rungs)
                    │
                    ▼
                  beam                (app substrate — this package)
```

`beam` is the future home of the generic, editor-agnostic runtime pieces:

- the generic model traits — `SchemaRecord` / `PersistsSchemaRecord` (ticket 07),
  media traits (ticket 08);
- the host-hook registries — webhook / sitemap / doctor.

## Layering law (ADR-0082)

`frame → beam`, **never** `beam → frame`. `beam` boots headless; nothing in it
may reference the editor rung. This is enforced by the boot smoke test, which
registers **only** `BeamServiceProvider` and asserts the frame provider is absent.

## Not a `Beam` model

`beam` is a substrate, not an instance. **No `Beam` Eloquent model is minted** —
that graduates only if a surface forces an instance registry (map fog).

## `splicewire:beam:doctor` — base-tier readiness

```
php artisan splicewire:beam:doctor
```

Audits whether a base-tier Beam app is deploy-ready. **Product-free**: it never requires
`splicewire/laravel-satellite`, and its frame / schema-forms / data-schemas checks are
**advisory and presence-conditional** (`class_exists` / `app()->bound()` at runtime) — on a
headless beam app where none are installed they emit an informational PASS/skip, never a
FAIL. It consumes the `Finding` + `DoctorStatus` primitives from `rushing/laravel-doctor`.

### Output format (the `<DoctorOutput>` parse target)

Each finding renders as **one line** in the stable form:

```
<check>: <detail>
```

at one of three levels — Pass → `info`, Warn → `warn`, Fail → `error` (the framework's
`$this->components` styling). The checks emitted, in order:

| check | gate? | fails on |
| --- | --- | --- |
| `lock path-free` | **hard** | a `type: path` package in `composer.lock` |
| `repos git-resolved` | **hard** | a committed `type: path` entry in `composer.json` `repositories` |
| `stability configured` | advisory | (warn only) dev-main pins without `minimum-stability`/`prefer-stable` |
| `schema round-trip` | advisory | never — skips when `data-schemas` absent |
| `frame manifest` | advisory | never — skips when `frame` absent |
| `schema-forms door` | advisory | never — skips when `schema-forms` absent |

The **exit code is non-zero only on a dependency-contract Fail** (`lock path-free` /
`repos git-resolved`). Every other check is advisory and never turns the run red — a headless
beam app with no editor rung installed is a valid, green configuration (ADR-0082 / ADR-0095).

## Frame operator seams — OOTB default bindings

`beam → frame` (ADR-0156): beam owns the model-backed CRUD driver behind Frame's agnostic
operator/admin sockets. Two of Frame's host-facing seams ship **out of the box** from
`BeamServiceProvider`, so a fresh host gets a working operator area with **no `app/Frame/` glue**:

| Frame contract | beam default | what it does |
| --- | --- | --- |
| `Schemastud\Frame\Contracts\FrameResourceHandlerResolver` | `Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver` | a constant map — every registered particle-resource key → the ONE `ParticleFrameResourceHandler` (which applies a registered `ParticleResource`'s enrichment when one exists under the key) |
| `Schemastud\Frame\Contracts\FrameFilterProvider` | `Splicewire\Beam\Frame\NullFrameFilterProvider` | an empty facet schema, so the ListShell's `filter-schema` / `filter-options` socket mounts without erroring (a resource declaring no data-filters `query` has no facets) |

Both are **overridable by the host** (last-binding-wins — an app provider registers after
beam-core's): bind your own `FrameResourceHandlerResolver` to map some keys to bespoke handlers,
or a real `FrameFilterProvider` (e.g. one derived from a data-filters query class) for a faceted list.

## Conventions

Matches the `rushing/*` / `schemastud/*` house style: **no `strict_types`, no
`final`, no `readonly`.**
