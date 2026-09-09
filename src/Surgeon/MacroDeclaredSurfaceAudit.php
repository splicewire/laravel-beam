<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Routing\BeamRouteProxy;

/**
 * **A surface whose shape is declared by the host-side `->beam()->returns()` macro** — migration debt,
 * counted so the doctrine's "deprecates by attrition" has an instrument instead of an assumption.
 *
 * The particle doctrine (`docs/agents/particle-doctrine.md`) sets the precedence
 * **macro → method attribute → particle-derived**, and says of the first: *"the host-side `->returns()`
 * macro deprecates by attrition, not by a rename."* Attrition without a count is a hope. This is the count.
 *
 * ## ⚠️ This is NOT {@see UndeclaredSurfaceAudit}, and conflating them is a measured bug
 *
 * The two ask different questions and a route can be a finding here while being perfectly fine there:
 *
 * | audit | question | a `->returns()` route |
 * |---|---|---|
 * | {@see UndeclaredSurfaceAudit} | does this surface declare a shape AT ALL? | **declared** — the macro is its FIRST listed legal site |
 * | this one | does it declare via the DEPRECATED site? | **a finding** |
 *
 * That distinction is not theoretical. Until 2026-09-09 `UndeclaredSurfaceAudit::isDeclared()` read
 * `getAction('returns')` while {@see BeamRouteProxy::set()} writes `action['beam']['returns']`, so every
 * macro-declared route counted as UNDECLARED and the ratchet overstated the gap by 72 rows at the
 * flagship. Codegen honoured those declarations the whole time — the routes have generated typed hooks
 * on disk. Rolling this audit's question into that one would resurrect exactly that defect.
 *
 * ## Advisory, permanently, and soft by construction
 *
 * A macro declaration is CORRECT today: it wins precedence, codegen reads it, and the client is typed.
 * Nothing is broken; there is simply a better site for new work. So this registers with `gate: false`
 * and emits {@see Finding::warn()} only — it may never join an exit code, and a host that never migrates
 * is not failing anything. Per `docs/agents/traps/audits-and-findings.md`, a check whose answer depends
 * on the host is advisory; which mounts a host has spelled is precisely such a fact.
 *
 * ## Reads through the writer's own constant
 *
 * `BeamRouteProxy::ACTION.'.returns'`, never a bare `'returns'` — the same mismatch that produced the
 * bug above. {@see \Splicewire\Beam\Routing\RouteActionMetadataReader} is the estate's reader and uses
 * the identical path; if this ever disagrees with it, this is what is wrong.
 */
class MacroDeclaredSurfaceAudit implements DoctorAudit
{
    public const CHECK = 'particle.macro-declared-surface';

    public function __construct(private Router $router) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = [];

        foreach ($this->router->getRoutes() as $route) {
            foreach (['returns', 'streams'] as $key) {
                if ($route->getAction(BeamRouteProxy::ACTION.'.'.$key)) {
                    $rows[] = [$this->name($route), $key];
                    break;
                }
            }
        }

        if ($rows === []) {
            return [Finding::pass(self::CHECK, 'No surface at this host declares its shape through the '
                .'`->beam()->returns()` / `->streams()` macro. The attrition the doctrine describes is '
                .'complete here — new work uses `#[ResponseFromData]` on the method.')];
        }

        $findings = [Finding::warn(self::CHECK, sprintf(
            '%d surface(s) declare their shape through the host-side `->beam()->%s` macro, which the '
                .'particle doctrine deprecates BY ATTRITION. Each is correctly declared and correctly '
                .'typed in the generated client — this is migration debt, not a contract hole, and it is '
                .'advisory: the repair is to move the declaration onto the controller method as '
                .'`#[ResponseFromData]`, one surface at a time, as each is next touched.',
            count($rows),
            $rows[0][1],
        ))];

        foreach ($rows as [$name, $key]) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '`%s` declares via `->beam()->%s()`. Move it to `#[ResponseFromData]` on the action when '
                    .'next edited; leaving it is not a defect.',
                $name,
                $key,
            ));
        }

        return $findings;
    }

    /** A route's stable identity for the report: its name where it has one, else method + uri. */
    private function name(RouteInstance $route): string
    {
        return $route->getName() ?: implode('|', $route->methods()).' '.$route->uri();
    }
}
