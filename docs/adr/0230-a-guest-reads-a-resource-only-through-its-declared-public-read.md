# ADR-0230 — A guest reads a resource only through its declared Public Read

**Status:** accepted — decided with the owner in editor-parity ticket 12, 2026-10-15
**Date:** 2026-10-15
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/editor-parity/` ticket 12 ("Binding blocks to particle
collections").
**Relates to:** `splicewire/laravel-beam-ux docs/adr/0229-a-block-binds-to-a-collection-by-declaration-resolved-per-request-as-the-visitor.md`
(the binding path that needs this rule), ADR-0223 (the precedent for a declared slot on a particle attribute whose
omission is counted).

## Context

Today route middleware decides whether a guest reads a resource. `ResourceVisibility::readable()` says so: "An
UNDECLARED resource is not refused to a guest here, because authenticating a request is the route middleware's job"
(`src/Authorization/ResourceVisibility.php:71-73`). The read guard asks each Gate arm of nobody when the actor is null
(`src/Authorization/ResourceReadGuard.php:36-49`), and it allows a read when a policy is bound without `viewAny` (`:129-130`).
So whatever a route lets through, the guard admits, and nothing says which rows or fields a guest receives.

Measured 2026-10-15 from the booted route tables of five hosts: GET routes that carry a particle resource (the `_particle`
route default), and those with no authenticating middleware (`auth`, `require.*`, `can:`, `verified`, `signed`, Sanctum):

| host | resource GET routes | with no authenticating middleware |
|---|---|---|
| `splicewire/splicewire-app` @ `37a913567` | 150 | 2 |
| `splicewire/splicewire` @ `425240f` | 3 | 2 |
| `rushing/audiostud` @ `aa1a7e6` | 45 | 4 |
| thingsontv @ `7787abb` | 0 | 0 |
| `splicewire/laravel-beam-starter` @ `6055718` | 5 | 2 |

Nine of the ten are discovery or hook-event listings, which describe routes, not rows. One reads rows:
audiostud's `GET resources/listen-songs`. That is a guest read by design, built by hand from three parts:

- a resource of its own, whose scope is written to be safe for a guest (`app/Data/Song/ListenSongProjectData.php:24-29`);
- a host `readPolicy` that "Admits every visitor; the resource's declared scope remains the public-row boundary"
  (`app/Authorization/PublicResourceReadPolicy.php:11`);
- a mount outside the auth group (`app/Providers/DomainResourceServiceProvider.php:134-139`).

A second probe, by policy rather than by route: of the ten `ResourceReadPolicy` implementations across `laravel-beam`,
`laravel-beam-ux`, `laravel-beam-accounts` and audiostud, only that one admits a null actor unconditionally.

`splicewire/laravel-beam-ux` ADR-0229 adds a second guest path. A public page's bound blocks read Records as the visitor,
who is usually a guest. On a Hosted site the page's author is a tenant who writes no code and mounts no middleware.
That path has no route of its own to lean on.

## Decision

1. **A resource may declare a Public Read.** It states which of the resource's rows a guest may receive (a scope) and
   which fields (a Data class, which may be the resource's own when every field it projects is public).
   `#[ParticleResource]` gains a `publicRead:` slot for it.
2. **A guest reads a resource only through its Public Read, on every path.** A null actor is refused a resource that
   declares none, whatever its route middleware lets through and whatever its `readPolicy` or model policy would answer.
   This covers the wire, the nav, the filter sub-surfaces, relatives, a binding (ADR-0229), and any later projection of
   the manifest. Route middleware is still the first gate, but it no longer decides alone.
3. **A guest receives the Public Read and nothing more.** Its rows are the resource's own scope narrowed by the Public
   Read's. Its fields are the Public Read Data class's. A guest's filters and sort may name only those fields, because a
   filter on a hidden field would answer questions about the field's value. An include reads the related resource, so a
   guest gets the related Records only through that resource's own Public Read.

## Consequences

- **audiostud's `listen-songs` declares a Public Read**: its own scope and `ListenSongProjectData`. Its
  `PublicResourceReadPolicy` retires. The two changes land together, or this rule refuses the guests audiostud's `/listen`
  page serves.
- **`ResourceVisibility::readable()`'s rule changes.** A guest is refused an undeclared resource there, and the docblock
  says so. `listable()` already never shows a null actor a model-less resource, the estate's rule that anonymous is
  bounded above by authenticated (`ResourceVisibility.php:74-79`).
- **A signed-in reader** keeps the existing guard. By the same bound, a signed-in reader whose own read is refused still
  receives what the Public Read gives a guest. Anything less would show a visitor less after signing in.
- **The residue is counted, as ADR-0223 counts undeclared affordances.** The doctor reports a resource that is reachable
  without an authenticating middleware and declares no Public Read. `splicewire:beam:make:particle-resource` emits the
  slot explicitly.
- **Listings that describe routes are unaffected.** A discovery listing or a hook-event catalog reads no rows.
- **Signed links are not decided here.** An operation's `signed:` slot makes a validly signed URL an admitting
  credential (particle-operation-surface ticket 14), and that map's census found one such operation, `LogInAsUser`.
  Whether a signed link's holder reads a resource as a guest under this rule is that map's to decide.
- **A Public Read is the resource owner's, not a site's.** A site author binds a Collection to it and can narrow it with
  a query, never widen it (laravel-beam-ux ADR-0229 decision 5).
- **Registered schemas fall under the same rule** however editor-parity ticket 18 decides to serve their records.
  *Extended by ADR-0235 decision 5 (2026-10-16): one resource serves every registered Collection's Records, and its
  Public Read reads each Collection's own declaration, `x-beam-public-read`, from the schema. The author who registers the
  schema is the owner this rule means, and a Collection that declares none gives a guest nothing.*

## Considered and not taken

- **Route middleware alone decides, as today.** A binding has no route of its own, and a Hosted site's author mounts no
  middleware, so the guest path would be decided by whoever happened to mount the route.
- **A `readPolicy` that admits a null actor**, audiostud's precedent. It says who may read and nothing about which rows or
  fields. A guest gets whatever the resource's Data projects, and nothing tells a public resource from a missing guard.
- **The rule on the binding path only.** Two rules would answer one question, and a guest could read through the API what
  the page refuses.
- **A Public Read that a site's author declares.** The resource's owner knows what is safe to publish; a site author can
  only narrow it.
