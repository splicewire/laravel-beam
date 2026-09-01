# Library orientation — what beam builds on

Brief, referential docs for the **primary** third-party libraries beam depends on: what each is
*here*, a concept index naming what exists and where to read it, the house overlay, and traps earned
from something that actually went wrong in this repo.

**Routers, not tutorials.** A library's own docs are better than any summary and are versioned with
the code. When a doc points at `vendor/…`, go read that — the pointer is the deliverable.

## The roster

| library | why it's here | version |
| --- | --- | --- |
| [`spatie/laravel-data`](spatie.laravel-data.md) | the substrate the particle doctrine extends | 4.23.0 (`^4.0\|^5.0`) |
| [`spatie/typescript-transformer`](spatie.typescript-transformer.md) | the emitted `.d.ts` beam's codegen reads back; beam annotates, the host transforms | 3.3.0 (`^3.0`) |
| [`nette/php-generator`](nette.php-generator.md) | beam **generates PHP** with it — the SDK and Saloon connectors | v4.2.2 (`^3.4\|^4.0`) |
| [`spatie/laravel-package-tools`](spatie.laravel-package-tools.md) | the provider skeleton, and where publishing meets migration ordering | 1.93.1 (`^1.16`) |
| [`orchestra/testbench`](orchestra.testbench.md) | how a package gets a Laravel app to test against at all | v11.1.0 (`^9.0\|^10.0\|^11.0`) |
| [`spatie/model-behaviour`](spatie.model-behaviour.md) | five trait-plus-table packages, learned together | activitylog 5.0.0 + four |

Run `pnpm deps --docs` from `~/Workspaces/splicewire-ecosystem` for the live version of this table,
including whether any doc has gone stale against the lock.

**Two of these span more than one major** (`^4.0|^5.0`, `^9.0|^10.0|^11.0`). That is a
package-tier obligation the hosts do not carry: beam's code must hold on every major it permits, so
an affordance you read about upstream is not automatically available here.

## Why this directory exists, in one incident

`particle-contribution-seam` ticket 12 found `project:` to be a hand-written reimplementation of
laravel-data's **magical creation**, and deleted **13 of the estate's 20 `project:` closures to a
no-op**. The map named the shape: *"a hand-rolled mechanism on top of a working one nobody knew was
there"* — its **third** instance, after `afterResolving` and the reverse index.

Nobody was careless. The affordance was documented, shipped in `vendor/`, and invisible. That is the
whole failure mode, and pointing at the docs is the whole fix.

## Beam is a package, which changes the rules

Read `vendor/spatie/laravel-data/docs/advanced-usage/in-packages.md` before assuming a host-tier
pattern transfers. Three differences bite repeatedly:

- **No config here.** Beam ships no `config/data.php`; the host owns it. A setting you want may not
  be yours to set.
- **Host-rooted discovery skips you.** `auto_discover_types` defaults to the *host's* `app_path()`,
  so a package's discoverable classes are invisible unless the host is configured to look.
- **Two majors, one codebase.** `^4.0|^5.0` means beam's code must hold on both, whatever upstream
  docs show for the newer one.

## Adding one

`/library-orientation write <vendor/name>`, or copy the shape from
[`spatie.laravel-data.md`](spatie.laravel-data.md).

A library qualifies only if it is **both** *measured* (clears the census threshold on resolved share
— `pnpm deps` from `~/Workspaces/splicewire-ecosystem`) and *written against* (you author code
against its API and its concepts have names). Reach alone promotes the formatter in 98% of repos
that nobody has needed to understand.

Under ~120 lines. If a section explains rather than points, the explanation belongs upstream and the
doc should link it. **Traps must be earned** — from something that actually went wrong here, with a
citation. Full convention:
`~/Workspaces/splicewire-ecosystem/docs/conventions/library-orientation.md`.
