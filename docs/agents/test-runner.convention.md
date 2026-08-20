# Convention — family repos test with Pest

**Scope:** every `rushing/*`, `schemastud/*`, `splicewire/*` package, plus the sites and starters.
**Status:** describes what the estate already does; the holdouts are a burn-down list, not a breakage.
**Detected by:** `Splicewire\Beam\Doctor\TestRunnerConformanceAudit` (advisory, runs in `beam:doctor`).

## The rule

A repo with a `tests/` directory declares `pestphp/pest`, ships a `tests/Pest.php`, and points its
composer `test` script at `vendor/bin/pest`.

## Why this is a convention and not a preference

It was already the estate's practice before anyone wrote it down. Measured 2026-08-20:

| Runner | Repos |
| --- | ---: |
| Pest | **71** |
| PHPUnit | 13 |
| neither | 1 |

Writing it down costs nothing and settles the recurring question. What made it worth writing down is
that the split has a **real cost at the boundary**, which is how this convention got filed:
api-surface-coherence ticket 24 moved six Scribe strategies from `splicewire/tower` (Pest) down into
`splicewire/laravel-beam` (PHPUnit), and their four test files **would not have parsed** at the
destination. They had to be converted by hand — `test('…', fn)` closures to class methods, `beforeEach`
to `setUp`, `expect()->toBe()` to `assertSame()`, file-scope fixture helpers to private methods.

That tax is paid on **every** cross-package move, and this estate moves code between packages constantly
— that is what the tier discipline *is*. A split test runner turns an otherwise mechanical descent into a
rewrite. The convention exists to stop that, not to have an opinion about assertion syntax.

## The holdouts

Thirteen repos, and they cluster rather than scatter — which is why this is tractable:

- **beam core and its arms** — `laravel-beam`, `laravel-beam-analytics`, `laravel-beam-dev`,
  `laravel-beam-embed`, `laravel-beam-mdx`, `laravel-beam-media`, `laravel-beam-sitemap`,
  `laravel-beam-ux`, `laravel-beam-ux-prototype`
- **the schemastud foundation** — `laravel-data-schemas`, `laravel-frame`
- **two rushing packages** — `laravel-data-schemas-scribe`, `laravel-schema-convergence`

`rushing/laravel-versioning-git` has a `tests/` directory and declares neither runner, which is its own
(worse) problem: a suite nothing can run reproducibly.

**Beam core shipping the audit while failing it is deliberate.** An advisory burn-down whose count is
expected to be non-zero is an established shape here — `TablePrefixBypassAudit` is the precedent.

## Adopting Pest is additive, and that is the point

**Pest runs PHPUnit test classes unchanged.** A repo adopts the convention in three mechanical steps
that convert nothing:

1. `composer require --dev pestphp/pest`
2. add `tests/Pest.php` (Pest binds its TestCase per *directory* there)
3. point the composer `test` script at `vendor/bin/pest`

Existing `class FooTest extends TestCase` files keep running. Converting them to `test()` closures is a
separate, optional, file-by-file exercise with no deadline — and the audit does not ask for it. What the
audit checks is that the **runner** is Pest, because that is what makes a test file portable across a
package boundary.

The reverse is not true, which is why the convention points this direction: PHPUnit cannot run Pest
files. It does not error on them either — it collects nothing and reports success, which is the silent
failure the audit's `pestIsWiredThrough()` check exists to catch.

## What is deliberately not automated

There is no surgeon operation paired with this audit. `HouseStyleAudit` pairs with one because its remedy
is a deletion (strip `final` / `readonly` / `strict_types`). A PHPUnit→Pest *conversion* is a
restructuring with per-file judgement in it, and a token-aware auto-rewrite would be a worse tool than an
honest report.
