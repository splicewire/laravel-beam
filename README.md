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

Audits whether a base-tier Beam app is deploy-ready. **Moat-free**: it never requires
`splicewire/laravel-satellite`, and its frame / schema-forms / data-schemas checks are
**advisory and presence-conditional** (`class_exists` / `app()->bound()` at runtime) — on a
headless beam app where none are installed they emit an informational PASS/skip, never a
FAIL. It consumes the `Finding` + `DoctorStatus` primitives from `schemastud/laravel-doctor`.

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

## Conventions

Matches the `rushing/*` / `schemastud/*` house style: **no `strict_types`, no
`final`, no `readonly`.**
