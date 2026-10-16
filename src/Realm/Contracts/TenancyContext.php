<?php

namespace Splicewire\Beam\Realm\Contracts;

/**
 * The port answering the live tenancy TOPOLOGY: is this deployment running a central/tenant split,
 * is a tenant initialized right now, and which configured connection is central (ADR-0236,
 * host-binding-seam ticket 01).
 *
 * Exists because the question had no owner below the packages that needed it, so it was answered by
 * 20 hand-copied `function_exists('tenant')` probes across six packages plus two deliberately
 * mirrored central-connection helpers — `Splicewire\Satellite\Support\SatelliteTenancy` and
 * `Splicewire\Beam\Tenancy\Support\TenancyConnections`, the latter documenting the duplication as
 * forced ("satellite sits ABOVE beam and beam-tenancy cannot depend upward"). This port is the home
 * that sentence was missing: core owns the contract, {@see \Splicewire\Beam\Realm\CentralTenancyContext}
 * answers for a host with no tenancy install, and beam-tenancy's adapter supersedes it with the live
 * stancl answers.
 *
 * Division of labour with the neighbours, so no caller picks the wrong tool:
 * - {@see TenantResolver} (sibling port) answers per-REALM tenant *resolvability* — a deployment-shape
 *   switch keyed by realm. This port answers the *process-wide* topology and the current moment.
 * - The `central` connection ALIAS (BeamServiceProvider, beam-facade ticket 96) makes the literal
 *   connection NAME resolve at hosts that never configured it, for models that PIN `central`. This
 *   port is the resolver kind — for readers with no model to pin. A resolver call is not a pin
 *   justification (`Splicewire\Beam\Surgeon\CentralPinJustificationAudit` reads declarations only).
 */
interface TenancyContext
{
    /**
     * Is a central/tenant split DECLARED AND RESOLVABLE on this deployment — stancl's
     * `tenancy.database.central_connection` names a configured connection? Deliberately not the
     * "central differs from the default" heuristic: on tower-shaped hosts the central connection IS
     * the boot default, which made the inequality test answer false on a multi-tenant host.
     */
    public function isMultiTenant(): bool;

    /** Is a tenant initialized on THIS request/process (never "is tenancy installed")? */
    public function isActive(): bool;

    /** The initialized tenant, or null. Package-agnostic on purpose: core names no tenancy library. */
    public function currentTenant(): ?object;

    public function currentTenantId(): int|string|null;

    /**
     * The connection name the central-estate schema lives on, or **null — never the default's NAME**.
     * Handing back `config('database.default')` would look identical and behave differently the
     * moment tenancy has swapped the default out from under the caller mid-request: null means "the
     * connection you would have used anyway", which is the honest statement when the host has
     * declared no central/tenant split. (`DB::connection(null)` / `Schema::connection(null)` already
     * mean exactly that.)
     */
    public function centralConnection(): ?string;
}
