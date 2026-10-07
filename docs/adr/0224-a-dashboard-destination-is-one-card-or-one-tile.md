# ADR-0224: A dashboard destination is one card or one tile

Status: accepted, 2026-10-06. Implements UX-14 / IA-16 and the owner's OQ-3 recommendation
(ecosystem 04de2be0). Amends ADR-0222 decisions 3–4; the owner retained the floor, not cards-only.

## Decision

A resource with a declared summary/overview renders one card when its provider answers. A seated
custom summary provider is also an explicit summary declaration. A default provider with no declared
context no longer creates a derived count card just because its resource is in the rail.
Every other product rail destination renders one tile. Cards win over tiles at the same href;
duplicate hrefs collapse. A provider that declines leaves its rail destination as a tile. Overview
wins over summary; Summary(false) opts out of cards, not navigation. The dashboard itself is omitted.
Developer-zone leaves render neither cards nor tiles, even with an explicit overview declaration.

The backing resolves the bound FrameNavContributor, so host-decorated rail projection is authoritative.
RailLeaves preserves the inherited Developer-zone classification, including nested projected nodes;
the actor-free doctor walk reads the same audience from NavSection declarations. No new config knob,
registry or host override is required. Widget payloads and the authorization boundaries are unchanged.

## Effect and rollout

This deliberately changes the default for every linked consumer when integrated: duplicate links and
undeclared derived cards disappear; product destinations remain reachable through their tiles.
Worktrees isolate implementation; no live symlink or bundle is changed by building the candidate.
The mission slice PROOF records host-by-host effects and outstanding primary screenshots/doctor proof.
Tests pin unique hrefs, decorated contributors, declaration ordering, declined fallback and developer
exclusion. Runbook ADR-0005 and frontend-surfaces amend the fleet rule in step.

Rule 11: grepped ADR-0222 and DashboardBacking/DashboardParticipation across ecosystem .scratch
before edits — 115 occurrences in 29 files at ecosystem 3bc6c808, including 9 occurrences in realm-dashboards and 0 in nav-contribution. The slice04
RULE11.md enumerates every match and its disposition; historical evidence is retained and superseded.
