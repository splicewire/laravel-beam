# ADR-0243: Packages own unversioned tier/domain API roots

Status: accepted (owner ruling 2026-11-12; helper signature ruled 18:42Z).

A package's public root is `/<tier>/<domain>`, without `/api` or a version segment. Its package config owns the default under a key spelled `api_root`; satellites use that default without carrying route configuration. Beam packages use `beam`, tower uses `splice`, and schemastud uses `stud`. The tier remains a policy/entitlement boundary, including the paid-engine seam at `/splice`.

Beam-family packages and tower call `Splicewire\Beam\Routing\ApiRoot::for(configKey, domain, tier: 'beam')` to resolve the root, or `ApiRoot::mount(Router, configKey, domain, Closure, tier: 'beam')` to register a group. The latter refuses an earlier catch-all by its route name before registering the group; hosts must put package roots before site/SPA catch-alls or exclude their tiers. Existing Laravel constraints are respected. Route files read the helper rather than spelling a root. Config roots are slash-trimmed, matching Laravel's relative group-prefix vocabulary. The package's core key is `beam.core.api_root`, default `beam/core`.

A foundation package beam depends on must not import beam to meet this rule. In particular, laravel-frame owns its own `api_root` key and `stud/frame` default, using its own mount code. The contract is a rule with two implementations required by dependency direction, not a reason to reverse that direction.

The flagship's own app routes stay at its unversioned `api` root. Operator and exec realm APIs are already unversioned and do not move. The `rushing` foundation packages do not take these tiers; a host group around them drops its version segment as part of host migration.

Migration is expand, migrate, contract: register both old and package-default roots, migrate known callers, then remove the old roots only on the owner's explicit word (ticket 16). Host policies keyed on old paths move before callers: CORS, cookie/session/CSRF, authorization/entitlement path rules, routing precedence, docs and generated clients must recognize the new roots first. Existing route mounts do not change in this prefactor.

This supersedes flagship ADR-0022's `v1` API contract and the `/api/{version}` part of ADR-0124; ADR-0124's owner-tier boundary survives. Flagship ADR marks are a host companion. Owner-review-20261026 ticket 32's prior default that v1 stays is superseded by this owner ruling; its correction is filed by the charter session, which owns that tracker. Owner-review-20261112 CONFORM row B-1 records adoption; it is marked by the charter session after proof.
