<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * **The `input:` twin of {@see UngatedOperationAudit}** — the count that gates
 * `null` → `false` on BOTH particle axes (api-surface-coherence 117).
 *
 * `input:` is three-state on {@see ParticleResource::$input} and {@see ParticleOperation::$input}: a
 * class-string (accept this), `false` (accept NOTHING, enforced), `null` (UNDECLARED — accept anything,
 * validate nothing). The third state is the residue, and until this audit existed **nothing counted it
 * on either axis**, so the flip's gate was a memory rather than a measurement. Two stale figures already
 * proved the point: `UngatedOperationAudit:16` and `ParticleOperation.php` both read *"14 of the 21"*
 * where the doctor reports `13 of 25`.
 *
 * ## Why a count and not a reject
 *
 * Settled by api-surface-coherence 69 on {@see UngatedOperationAudit}'s precedent: the obvious move —
 * throw on `null` at registration — was measured on the `ability:` axis and refused because it would
 * fail BOOT. The estate's answer to *"what does undeclared mean"* has never been *"rejected"*; it is
 * **"COUNTED, loudly, until it is zero"**. This is that count. The flip is what it gates.
 *
 * ## Two checks, not one — because the axes are decoupled
 *
 * One audit class, two {@see Finding}s under two check names. The doctrine is one; the SEVERITY is not.
 * The resource axis's `false` is enforced today ({@see ParticleController::rejectInput()}) and its
 * reachable residue is zero; the op axis still treats `false` and `null` identically and carries a
 * residue in this host and five more declarations estate-wide. Folding them into a single check would
 * let one axis's warn mask the other's pass and imply the two flip together — which is exactly the
 * coupling 69 killed.
 *
 * ## The resource axis counts REACHABLE write mounts, derived per run
 *
 * ⚠️ This is the whole reason the audit is route-side rather than a registry scan, and it is the defect
 * this map keeps finding stated once more: **an audit that reads the attribute rather than the route it
 * serves is measuring the declaration, not the surface.** Measured at the flagship 2026-08-28: 23 write
 * mounts sit on an `input: null` resource, and **22 of them are the saved-filters sub-surface** —
 * {@see ResourceFiltersController}, which extends plain `Controller`,
 * declares its own `SavedFilterStoreInputData`/`SavedFilterUpdateInputData`, and never calls
 * `parseInput()`. A declaration count reports 23 and cries wolf; this reports the ONE that reaches
 * `parseInput()` — which 69 then fixed, so it reports zero.
 *
 * Reachability is therefore: a route stamped {@see ParticleController::RESOURCE}, answering a
 * body-bearing verb (POST/PUT/PATCH — `destroy` is DELETE and parses no input), whose controller is-a
 * {@see ParticleController}. That last clause is what excludes the filter sub-surface, and it is
 * derived from the router on every run rather than listed — a read-only resource that later gains a
 * write mount re-enters the count by itself.
 *
 * The op axis is registry-side, because there is no equivalent hole: every mounted op runs through
 * {@see ParticleOperationController::invoke()}, which reads the declaration unconditionally. An op
 * registered but never mounted is still counted — deliberately: it is a declaration THIS host installed
 * and one route file away from live.
 *
 * ## The `media.ingest` carve-out is spelled HERE, in the audit
 *
 * 69 ruled the flip ships with a NAMED carve-out rather than an asterisk on an unreachable gate, and
 * left the spelling to 117. Of the three shapes 69 enumerated — a fourth declaration state, a
 * host-contributed input port, or "rule it legal and report it as acknowledged" — this takes the third,
 * with the acknowledgement living in {@see ACKNOWLEDGED} rather than in the declaration. That is the
 * condition 69's own ⚠️ put on it, and it is what stops option 3 collapsing into option 1: no fourth
 * state enters the attribute surface for the sake of one operation.
 *
 * `media.ingest` earns it structurally, not by seniority. beam-media owns the OPERATION; the HOST owns
 * what its request means — `handle()` hands the whole `Request` to the bound `MediaIngestor` port, so
 * any class named in the declaration would be true in the flagship and a LIE in another host, and a
 * bare beam-media install (no ingestor bound) would want `false`, the opposite answer. See
 * `IngestMedia`'s own refusal comment, which points back at this constant.
 *
 * An acknowledgement is a KEY plus a reason, and it is reported — an acknowledged op appears in the
 * finding under its own heading, never silently subtracted. A key that carries no acknowledgement is
 * outstanding; a key acknowledged here that has since declared an input is reported as a **stale**
 * acknowledgement, so the carve-out cannot outlive its reason.
 *
 * ⚠️ Warn on both axes, never Fail. An undeclared `input:` is not an OPEN endpoint — the resource's
 * `prepare`/`withoutStructuralColumns` guards and the op's own handler may cover it, and for several
 * live ones they do. What is true of all of them is that the DECLARATION cannot be read to find out,
 * which is a work-list line and not a red gate.
 */
class UndeclaredInputAudit implements DoctorAudit
{
    /** The resource axis: undeclared `input:` reachable from a live write mount. */
    public const CHECK_RESOURCES = 'particle.resource-input';

    /** The operation axis: undeclared `input:` on a registered `#[ParticleOp]`. */
    public const CHECK_OPERATIONS = 'particle.operation-input';

    /**
     * Operation keys whose `input: null` is a DECISION this audit has accepted, each with the reason.
     *
     * The carve-out spelling api-surface-coherence 69 deferred to 117. String keys only — beam-core
     * never learns a consumer's class, and this never becomes a declaration state.
     *
     * @var array<string, string>
     */
    public const ACKNOWLEDGED = [
        'media.ingest' => 'beam-media owns the op, the HOST owns what its request means — `handle()` '
            .'passes the whole Request to the bound MediaIngestor port, so any class named would be '
            .'true in one host and a lie in the next, and a bare install would want `false`. Until a '
            .'host-contributed input port exists (69 Q5 option 2), this is the honest state.',
    ];

    /** Verbs that carry a body through the generic write pipeline. `destroy` is DELETE and parses none. */
    private const WRITE_VERBS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        protected Router $router,
        protected ParticleResourceRegistry $resources,
        protected ParticleOperationRegistry $operations,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        return [$this->resourceAxis(), $this->operationAxis()];
    }

    /**
     * Undeclared `input:` on resources REACHABLE from a live write mount — derived from the router on
     * every run, never a list.
     */
    private function resourceAxis(): Finding
    {
        /** @var array<string, ParticleResource> $byKey */
        $byKey = [];

        foreach ($this->resources->unfiltered()->matches('beam.particle.resources') as $resource) {
            $byKey[$resource->key] = $resource;
        }

        $mounts = 0;
        $undeclared = [];

        foreach ($this->writeMounts() as $key => $routes) {
            $resource = $byKey[$key] ?? null;

            // A stamp naming nothing registered here is ParticleRouteResourceAudit's finding, not ours.
            if ($resource === null) {
                continue;
            }

            $mounts += count($routes);

            if ($resource->input === null) {
                $undeclared[$key] = $routes;
            }
        }

        if ($mounts === 0) {
            return Finding::inconclusive(self::CHECK_RESOURCES, 'No particle write mount on this host reaches '
                .'`ParticleController::parseInput()` — nothing to declare.');
        }

        if ($undeclared === []) {
            return Finding::pass(self::CHECK_RESOURCES, sprintf(
                '%d reachable particle write mount%s, every one on a resource that DECLARES its `input:` '
                .'(a class-string, or `false` for "accepts nothing"). The reachable `null` residue is '
                .'empty — the gate ParticleResource::$input\'s null → false flip waits on.',
                $mounts,
                $mounts === 1 ? '' : 's',
            ));
        }

        $affected = array_sum(array_map('count', $undeclared));

        return Finding::warn(self::CHECK_RESOURCES, sprintf(
            '%d of %d reachable particle write mount%s land on a resource declaring no `input:` — neither '
            .'a Data class nor an explicit `false`. Their bodies are snake-mapped onto the model whole, '
            .'AFTER the `prepare()` hook, so a client-sent attribute beats a server-derived one silently '
            ."(that is the shape of the `seller-repo-authorizations` forgery, api-surface-coherence 69).\n\n"
            .'Declare `input: <Something>InputData::class`, or `input: false` if every attribute is '
            .'server-derived. Counted over ROUTES, not declarations: the saved-filters sub-surface '
            ."declares its own input and is correctly absent.\n%s",
            $affected,
            $mounts,
            $mounts === 1 ? '' : 's',
            implode("\n", array_map(
                fn (string $key, array $routes): string => sprintf(
                    '  %-38s %d mount%s (e.g. %s)',
                    $key,
                    count($routes),
                    count($routes) === 1 ? '' : 's',
                    $routes[0],
                ),
                array_keys($undeclared),
                $undeclared,
            )),
        ));
    }

    /**
     * Every route that reaches {@see ParticleController::parseInput()}, keyed by resource.
     *
     * @return array<string, list<string>>
     */
    private function writeMounts(): array
    {
        $mounts = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $defaults = $route->defaults;

            // An op route carries its own stamp and its own axis.
            if (isset($defaults[ParticleOperationController::RESOURCE])) {
                continue;
            }

            $key = $defaults[ParticleController::RESOURCE] ?? null;

            if (! is_string($key)) {
                continue;
            }

            $verbs = array_values(array_intersect($route->methods(), self::WRITE_VERBS));

            if ($verbs === [] || ! $this->throughParticleController($route)) {
                continue;
            }

            $mounts[$key][] = sprintf('%s %s', $verbs[0], $route->uri());
        }

        return $mounts;
    }

    /**
     * Whether the route's action is a {@see ParticleController} (or subclass) — the clause that excludes
     * the saved-filters sub-surface, whose controller extends plain `Controller` and validates its own
     * input.
     *
     * Read off the action STRING rather than `getController()`, so the audit never instantiates a
     * controller to ask a question about routing.
     */
    private function throughParticleController(Route $route): bool
    {
        $uses = $route->getAction('uses');

        if (! is_string($uses)) {
            return false;
        }

        $class = strtok($uses, '@');

        return is_string($class) && is_a($class, ParticleController::class, allow_string: true);
    }

    /** Undeclared `input:` on registered operations, minus the acknowledged carve-outs. */
    private function operationAxis(): Finding
    {
        $all = $this->operations->unfiltered()->matches('beam.particle.operations');

        if ($all === []) {
            return Finding::inconclusive(self::CHECK_OPERATIONS, 'No particle operations are registered in this host.');
        }

        $outstanding = [];
        $acknowledged = [];
        $stale = [];

        foreach ($all as $operation) {
            /** @var ParticleOperation $operation */
            $key = $operation->key();
            $isAcknowledged = array_key_exists($key, self::ACKNOWLEDGED);

            if ($operation->input !== null) {
                if ($isAcknowledged) {
                    $stale[] = $key;
                }

                continue;
            }

            if ($isAcknowledged) {
                $acknowledged[] = $key;

                continue;
            }

            $outstanding[] = $key;
        }

        $declared = count($all) - count($outstanding) - count($acknowledged);

        if ($outstanding === [] && $stale === []) {
            return Finding::pass(self::CHECK_OPERATIONS, sprintf(
                '%d/%d particle operations declare their `input:`%s. The unacknowledged `null` residue is '
                .'empty — the gate ParticleOperation::$input\'s null → false flip waits on.',
                $declared,
                count($all),
                $acknowledged === []
                    ? ''
                    : sprintf(', and the %d acknowledged carve-out%s (%s) is host-bound by construction',
                        count($acknowledged),
                        count($acknowledged) === 1 ? '' : 's',
                        implode(', ', $acknowledged)),
            ));
        }

        $lines = [];

        foreach ($outstanding as $key) {
            $lines[] = sprintf('  %-38s → declare `input:` or `input: false`', $key);
        }

        foreach ($stale as $key) {
            $lines[] = sprintf('  %-38s → STALE acknowledgement: it now declares an input, so its entry '
                .'in UndeclaredInputAudit::ACKNOWLEDGED should be deleted', $key);
        }

        return Finding::warn(self::CHECK_OPERATIONS, sprintf(
            '%d of %d particle operations declare no `input:` — neither a Data class nor an explicit '
            .'`false`. Each accepts anything and validates nothing, and the declaration cannot be read to '
            ."find out what it expects.\n\nDeclare a Data class, or `input: false` with a comment saying "
            .'why (the shape `Splicewire\\Beam\\Accounts\\Ops\\StopImpersonating` argues). A `null` that is '
            .'a DECISION rather than an omission belongs in UndeclaredInputAudit::ACKNOWLEDGED with its '
            ."reason, not left to read as unfinished work%s:\n%s",
            count($outstanding),
            count($all),
            $acknowledged === []
                ? ''
                : sprintf(' (%d already acknowledged: %s)', count($acknowledged), implode(', ', $acknowledged)),
            implode("\n", $lines),
        ));
    }
}
