<?php

namespace Splicewire\Beam\Surface;

/**
 * The **security posture facets** a live route is projected onto — the contract every consumer of
 * {@see RuntimeCorroborator} binds to by name, so this list is a real interface and not an
 * implementation detail. Adding a case widens what can be corroborated; renaming one breaks every
 * stored finding that cited it.
 *
 * A facet a projector cannot determine is **omitted from the projection entirely**, never defaulted.
 * That is the whole discipline: a `false` here means "the router shows this is not the case", and an
 * absent key means "we could not tell" — which lands downstream as a gap. Defaulting an unknown facet
 * to `false` would manufacture violations; defaulting it to `true` would manufacture a clean audit.
 * Both are worse than saying nothing, and only the omission can be counted honestly.
 *
 * Scope note that keeps these claims true: every facet is answered from the **application's** router
 * and registries. Protection applied outside the application — a WAF rule, an edge rate limit, an
 * upstream mTLS gateway — is invisible here, so `RateLimited => false` means "this application applies
 * no rate limit to this route", not "this route is unlimited in production".
 */
enum PostureFacet: string
{
    /** The route is behind an authentication middleware. */
    case AuthRequired = 'auth_required';

    /** The route names an authorization gate — a `can:` middleware, or a declared particle `ability:`. */
    case AuthorizationPolicy = 'authorization_policy';

    /** The route initializes tenancy, so it resolves against a tenant's connection rather than central. */
    case TenantScoped = 'tenant_scoped';

    /** The application applies a request rate limit to the route. */
    case RateLimited = 'rate_limited';

    /** Reaching the route leaves an audit trail. */
    case AuditLogged = 'audit_logged';
}
