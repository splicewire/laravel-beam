# Host bindings — defaults, supersession and posture keys

> **Seam:** beam-tier contract reference · stack-blind · every mechanism it names ships in a family
> package; a host binds only to CUSTOMIZE.

*Homed in `splicewire/laravel-beam` beside the particle doctrine (host-binding-seam ticket 06,
2026-10-16). One screen; everything below is a cite. The grilled record is the
[host-binding-seam map](~/Workspaces/splicewire-ecosystem/.scratch/splicewire/splicewire-ecosystem/host-binding-seam/MAP.md).*

## The invariant

> A package that ships a port ships its **default**. A fresh host resolves and behaves; the host
> interface is "override what you customize", never "replicate the flagship's AppServiceProvider."

Measured cost of its absence (architecture pass, 2026-10-16): zero `bindIf` in tower's providers,
container not-instantiable at request time on fresh starters, a 299-line hand-copied tenancy
provider with exactly one copy, and 20 hand-copied `function_exists('tenant')` probes.

## Two lanes and a decorator

**Container lane** — both ends are classes; the container is the seam.

- **Default:** `bindIf`. Exemplars: `ActorPort → GuardActorAdapter`
  (`BeamServiceProvider.php`, the `bindIf` with its why-comment), saloon-kernel's
  `KernelContextBridge → NullContextBridge` (`SaloonKernelServiceProvider.php:24`), beam-commerce's
  posture-keyed `EntitlementGate` (its ADR-0004), `TenancyContext → CentralTenancyContext`
  (ADR-0239).
- **Supersession:** plain `bind()`, always behind an explicit posture — licenser's gate bind behind
  `beam.licenser.enabled` (`BeamLicenserServiceProvider.php:42`); beam-tenancy's stancl
  `TenancyContext` adapter, kernel bridge and vault guard, where *installing the tenancy package IS
  the posture* (its ADR-0004 — note the vault case: auth-vault `bindIf`s its own Null first, so a
  second `bindIf` would lose; supersession was the point).
- **The pair is order-independent.** `bind()` replaces whatever is there; `bindIf` never does — so
  default + supersession compose in either registration order. Never lean on name-sorted package
  order; it is a coincidence, not a contract.
- **Decorator:** `extend()` when the override wraps the default instead of replacing it — tower's
  `GuestTokenActorAdapter` over beam's `ActorPort` (`TowerServiceProvider.php`, the one `extend`).

**Config lane** — the downstream package resolves off ITS config key, so the default is a **config
write, not a container bind**.

- **Default:** null-guarded write. Exemplar: beam-commerce's kernel entitlement resolver —
  "we set the CONFIG key (not the container binding) so the kernel provider's own lazy singleton
  resolves this adapter" (`BeamCommerceServiceProvider.php`, `bindKernelEntitlementResolver`).
- **Host config must win:** add the `packageBooted` timing guard — `bindTenantModel`'s triple guard
  is the canonical shape (`BeamTenancyServiceProvider.php`, its "Guarded THREE ways" docblock).
- ⚠️ **This lane is order-sensitive across packages** (null-guard = first writer wins; unconditional
  write = last writer wins). Prefer the container lane whenever both ends are classes.

## Soft edges

A default against an optional dependency guards on `interface_exists` and carries the contract in
require-dev + suggest, never a hard require — licenser's shape, reused by beam-tenancy's bridge and
vault guard. The class file itself then fatals ordinary autoload walkers when the interface is
absent; a `class_exists` sweep must catch `Throwable` (beam-tenancy's Data-walker carries the
comment).

## Two gates, one name

`Splicewire\Beam\Entitlements\EntitlementGate` (THIS package, a class) is the feature-membership
gate over the kernel resolver — inert by default, the thing `entitlement:{key}` abilities resolve.
`Splicewire\Beam\Commerce\Entitlements\EntitlementGate` (an interface) is the plan-cascade
capability gate Ring-1 services inject, extraction issue-01's bound contract. They are deliberately
distinct (each docblock says so); cite the FQCN when you mean either.

## The audit

Every shipped default and every host-expected binding gets a `RequiredBindingsAudit` row
(host-binding-seam ticket 05) with one of three kinds: `required` (unbound = crash),
`defaulted-overridable` (asserts resolvable + names the effective adapter), `silent-when-absent`
(nullable seams — tower's `ConduitQuota` is why "resolvable" probes lie: unbound just silently
skips). An audit that cannot fail is not an audit.
