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

## Conventions

Matches the `rushing/*` / `schemastud/*` house style: **no `strict_types`, no
`final`, no `readonly`.**
