<?php

namespace Splicewire\Beam\Doctor;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Superseded;
use Splicewire\Beam\Conformance\BeamManifest;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\FlowState;
use Throwable;

/**
 * The doctor's catalog join (browser-journeys 11): it reads the live {@see FlowCatalog} that every
 * installed package declares its flows into, resolves each flow's gate for THIS host, reads the host's
 * `BEAM.md` through {@see BeamManifest}, and classifies each flow in the PRD's fixed order (02 R3-Q1).
 * It then checks each key a `BEAM.md` Deviation names back against the catalog (02 R3-Q3).
 *
 * ## It gates, and it never throws
 *
 * Registered `gate: true` ({@see \Splicewire\Beam\BeamServiceProvider}), so a genuine catalog↔host
 * disagreement — a live flow pointing at an entry route the host never registered, or a `BEAM.md`
 * naming a key no installed package declares — fails the run. Everything else is a WARN (a Delta or a
 * stale Deviation: the work-list a converged repo runs `--floor=warn` to drive to zero) or a Pass note
 * (a deviated flow correctly off, a structurally-absent flow with no test expected).
 *
 * Every answer is HOST-DEPENDENT and the doctor runs from artisan, where a gate throwing, a flow with
 * no parent surface, or a `BEAM.md` that does not exist are ordinary states of a partially-composed
 * host, not defects in this audit. So a gate is resolved and read inside a `try` and a throw becomes a
 * WARN rather than propagating ({@see \Splicewire\Beam\Flows\FlowGate}'s own contract says it must not
 * throw and that the doctor catches it when it does); a flow whose parent surface is not declared, or
 * whose gate reports {@see FlowState::Absent}, is a structural-absence note, never an error; and an
 * empty catalog, an absent router, or a missing manifest is answered, not thrown over. A host that
 * declares no flows reports one inconclusive Pass ({@see Finding::inconclusive()}) naming the empty
 * population, per the {@see DoctorAudit} contract.
 *
 * ## Why the classification ORDER is load-bearing (02 R3-Q1)
 *
 * The three steps are tried in order and the first that applies wins, so a flow is never both
 * "structurally absent" and "a missing route". A flow the host turned off with a matching Deviation is
 * classified `deviated` at step 2 and never reaches the route check at step 3 — which is exactly why a
 * closed registration with a matching Deviation produces no missing-route error. Moving the route check
 * ahead of the Deviation check would fail a host for a route it deliberately switched off and declared.
 *
 * ## Relative vs absolute flow keys
 *
 * {@see FlowCatalog::keys()} answers ABSOLUTE keys (`beam.flows.account`), while a Deviation names a
 * RELATIVE flow key (`teams.team-of-one`) and {@see BeamManifest::deviates()} splits on the first dot —
 * so the catalog's own root must be off the key before it is matched against a Deviation. Each flow's
 * relative key is read straight off its {@see FlowDeclaration::$key}; the root is derived once from the
 * first absolute/relative pair (never hardcoded), and used only to rebuild the absolute form a
 * {@see PopcornManager::suggest()} did-you-mean needs.
 */
class FlowDoctorAudit implements DoctorAudit
{
    /** The flow-classification check; every finding names its sub-check in a `[tag]` prefix. */
    public const CHECK = 'flow.catalog';

    /** The `BEAM.md`-key-against-catalog check (02 R3-Q3). */
    public const CHECK_BEAM_KEY = 'flow.beam-md';

    public const TAG_ABSENT = 'structural-absence';

    public const TAG_DEVIATED = 'deviated';

    public const TAG_DELTA = 'delta';

    public const TAG_STALE = 'stale';

    public const TAG_ROUTE = 'unregistered-route';

    public const TAG_GATE_THREW = 'gate-threw';

    public const TAG_SUPERSEDED = 'superseded';

    public const TAG_DECLARED = 'declared';

    public const TAG_PACKAGE_ABSENT = 'package-absent';

    public const TAG_PACKAGE_INSTALLED = 'package-installed';

    public const TAG_UNATTRIBUTED = 'unattributed';

    public function __construct(
        protected Application $app,
        protected FlowCatalog $catalog,
        protected PopcornManager $popcorn,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $absolute = array_map('strval', $this->catalog->keys());

        if ($absolute === []) {
            // Nothing to measure — a host with no flow-shipping package is a legal, empty catalog
            // (FlowCatalog's PopulationRequirement::Optional). Say so rather than reporting a clean pass.
            return [Finding::inconclusive(
                self::CHECK,
                'No flows are declared in this host — `beam.flows` is empty, so there is nothing to classify.'
            )];
        }

        // keys()/keysBy()/suggest() answer the ABSOLUTE key (root-prefixed, e.g. `beam.flows.billing`);
        // declarations()/has()/deviates() speak the RELATIVE key as the caller declared it (`billing`) —
        // declarations() filters on `$flow->key === $key` and a declaration's key is relative, so an
        // absolute query matches nothing. Strip the catalog's own root before every relative read.
        $root = $this->catalogRoot();

        $flows = [];
        foreach ($absolute as $abs) {
            $rel = $this->relativize($abs, $root);
            $declarations = $this->catalog->declarations($rel);
            if ($declarations === []) {
                continue;
            }
            $flows[] = ['abs' => $abs, 'rel' => $rel, 'declarations' => $declarations];
        }

        if ($flows === []) {
            return [Finding::inconclusive(self::CHECK, 'No flow declarations are readable in `beam.flows`.')];
        }

        $relativeKeys = array_column($flows, 'rel');
        $manifest = $this->manifest();
        $registrants = $this->registrantUniverse($absolute);

        $findings = [];

        // 1) Classify each declared flow, in the PRD's fixed order.
        foreach ($flows as $flow) {
            foreach ($this->classify($flow, $manifest, $registrants) as $finding) {
                $findings[] = $finding;
            }
        }

        // 2) Check each key a BEAM.md Deviation names, back against the catalog.
        foreach ($this->beamKeyFindings($manifest, $relativeKeys, $root) as $finding) {
            $findings[] = $finding;
        }

        $findings[] = $this->summary($flows);

        return $findings;
    }

    /* ---------------- (1) flow classification ---------------- */

    /**
     * Classify one flow. Returns the one finding its state produces, or `[]` when it is plainly on, not
     * deviated, and all its entry routes resolve (the common case, folded into {@see summary()}).
     *
     * @param  array{abs: string, rel: string, declarations: list<FlowDeclaration>}  $flow
     * @param  list<string>  $registrants
     * @return list<Finding>
     */
    protected function classify(array $flow, BeamManifest $manifest, array $registrants): array
    {
        $key = $flow['rel'];
        $declarations = $flow['declarations'];

        // A supersession is reported before anything else: under OnKeyDuplicate::Supersede a package
        // that re-declares a key OVERRIDES it (later registrant wins), and the displaced declaration is
        // recorded as supersession history — never read. declarations() therefore holds the one live
        // winner; the displaced registrant(s) are read from superseded(), the way frame reads its own
        // displaced declarations rather than keeping a parallel list (owner ruling 2026-11-24). Name the
        // winner and every displaced registrant, and stop — the superseded entry is not a second flow to
        // classify.
        $displaced = array_values(array_unique(array_filter(array_map(
            static fn (Superseded $s): ?string => $s->by,
            $this->catalog->superseded($key),
        ))));
        if ($displaced !== []) {
            return [Finding::warn(self::CHECK, sprintf(
                '[%s] `%s` is declared by more than one installed package; the later registrant (%s) '.
                'supersedes %s. Supersede keeps exactly one live declaration — the winner — and records '.
                'the rest. A package that extends another should register a sibling/child key, not '.
                're-declare this one.',
                self::TAG_SUPERSEDED,
                $key,
                $this->registrantsAt($flow['abs'], $registrants),
                implode(', ', $displaced),
            ))];
        }

        $declaration = $declarations[0];

        // Step 1: structural absence — a flow whose parent surface is not declared on this host, or
        // whose gate reports Absent, does not exist to switch. No test is expected and it is never an
        // error (a cross-package flow like `account.billing` on a host without beam-accounts is the
        // designed case).
        $parent = $declaration->parent();
        if ($parent !== null && ! $this->catalog->has($parent)) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                '[%s] `%s` is declared, but its parent surface `%s` is not declared on this host — the '.
                'flow is structurally absent (the package that owns the surface is not installed). No '.
                'test is expected.',
                self::TAG_ABSENT,
                $key,
                $parent,
            ))];
        }

        $state = $this->gateState($declaration);

        // A gate that threw is a bug in that gate, caught here rather than propagated (FlowGate's
        // contract). Host-dependent and never fatal on its own — a WARN.
        if ($state instanceof Throwable) {
            return [Finding::warn(self::CHECK, sprintf(
                '[%s] `%s` gate %s threw when asked for its state (%s). A FlowGate must not throw; the '.
                'doctor reports it instead of letting it take the host down.',
                self::TAG_GATE_THREW,
                $key,
                $this->shortName($declaration->gate),
                $state->getMessage(),
            ))];
        }

        if ($state === FlowState::Absent) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                '[%s] `%s` gate %s reports Absent — the capability is structurally absent from this host '.
                '(not on, not off). No test is expected.',
                self::TAG_ABSENT,
                $key,
                $this->shortName($declaration->gate),
            ))];
        }

        $deviates = $manifest->deviates($key);

        // Step 2: off/on against the manifest's Deviations.
        if ($state === FlowState::Off) {
            return [$deviates
                ? Finding::pass(self::CHECK, sprintf(
                    '[%s] `%s` is Off and a BEAM.md Deviation covers it — an intentional, declared '.
                    'divergence.',
                    self::TAG_DEVIATED,
                    $key,
                ))
                : Finding::warn(self::CHECK, sprintf(
                    '[%s] `%s` is Off but no BEAM.md Deviation covers it — a detected-but-unreconciled '.
                    'Delta. Declare a Deviation for it, or turn the flow back on.',
                    self::TAG_DELTA,
                    $key,
                ))];
        }

        // state is On.
        if ($deviates) {
            return [Finding::warn(self::CHECK, sprintf(
                '[%s] `%s` is On, but a BEAM.md Deviation says it is off — the Deviation is stale. Remove '.
                'it, or turn the flow off.',
                self::TAG_STALE,
                $key,
            ))];
        }

        // Step 3: On and not deviated — every declared entry route must resolve in the host route table.
        $missing = $this->missingRoutes($declaration);
        if ($missing !== []) {
            return [Finding::fail(self::CHECK, sprintf(
                '[%s] `%s` is On but declares entry route name(s) not registered in this host: %s. Either '.
                'the route is unregistered, or the flow should be switched off and a Deviation declared.',
                self::TAG_ROUTE,
                $key,
                implode(', ', array_map(static fn (string $r): string => "`{$r}`", $missing)),
            ))];
        }

        return [];
    }

    /* ---------------- (2) BEAM.md key against the catalog ---------------- */

    /**
     * Every key a BEAM.md Deviation names, checked back against the catalog (02 R3-Q3). An omitted
     * Deviation names its branch (the surface); a partial one names `branch.flow` per `flows:` entry.
     *
     * @param  list<string>  $catalogKeys  relative flow keys
     * @return list<Finding>
     */
    protected function beamKeyFindings(BeamManifest $manifest, array $catalogKeys, string $root): array
    {
        $findings = [];

        foreach ($manifest->deviations() as $deviation) {
            $branch = $deviation['branch'] ?? null;
            if (! is_string($branch) || $branch === '') {
                continue;
            }

            $package = isset($deviation['package']) && is_string($deviation['package'])
                ? $deviation['package']
                : null;

            foreach ($this->deviationKeys($branch, $deviation) as $key) {
                $findings[] = $this->beamKeyFinding($key, $package, $catalogKeys, $root);
            }
        }

        return $findings;
    }

    /**
     * The keys one Deviation references: its branch when it omits the whole branch (status omitted /
     * absent / none), or `branch.flow` for each `flows:` entry when it is partial.
     *
     * @param  array<string, mixed>  $deviation
     * @return list<string>
     */
    protected function deviationKeys(string $branch, array $deviation): array
    {
        $status = $deviation['status'] ?? 'omitted';
        $flows = array_values(array_filter(
            (array) ($deviation['flows'] ?? []),
            'is_string',
        ));

        if ($status !== 'partial' || $flows === []) {
            return [$branch];
        }

        return array_map(static fn (string $flow): string => $branch.'.'.$flow, $flows);
    }

    /**
     * One BEAM.md key's verdict (02 R3-Q3's four outcomes):
     *  - declared in the catalog → fine, naming the registrant beside it;
     *  - undeclared, carries `package:` NOT installed → a note (the package was removed, the key is
     *    legitimately absent);
     *  - undeclared, carries `package:` that IS installed → an error (a typo, or a flow the package
     *    dropped);
     *  - undeclared, carries no `package:` → an error, with a Popcorn did-you-mean.
     *
     * @param  list<string>  $catalogKeys  relative flow keys
     */
    protected function beamKeyFinding(string $key, ?string $package, array $catalogKeys, string $root): Finding
    {
        if ($this->catalog->has($key)) {
            $registrant = $this->catalog->registrantOf($key);

            return Finding::pass(self::CHECK_BEAM_KEY, sprintf(
                '[%s] BEAM.md Deviation `%s` is declared in the catalog%s.',
                self::TAG_DECLARED,
                $key,
                $registrant === null ? '' : ' by '.$registrant,
            ));
        }

        if ($package !== null) {
            if (! $this->packageInstalled($package)) {
                return Finding::inconclusive(self::CHECK_BEAM_KEY, sprintf(
                    '[%s] BEAM.md Deviation `%s` is not declared in the catalog, and its `package: %s` is '.
                    'not installed — the key is structurally absent (the package was removed). No action '.
                    'needed.',
                    self::TAG_PACKAGE_ABSENT,
                    $key,
                    $package,
                ));
            }

            return Finding::fail(self::CHECK_BEAM_KEY, sprintf(
                '[%s] BEAM.md Deviation `%s` names `package: %s`, which IS installed, yet the package '.
                'declares no such flow — a typo in the Deviation, or a flow the package dropped. Fix the '.
                'key, or remove the Deviation.',
                self::TAG_PACKAGE_INSTALLED,
                $key,
                $package,
            ));
        }

        return Finding::fail(self::CHECK_BEAM_KEY, sprintf(
            '[%s] no installed package declares `%s`, named by a BEAM.md Deviation%s. Attribute the '.
            'Deviation with `package:` if a now-removed package owned it, or fix the key.',
            self::TAG_UNATTRIBUTED,
            $key,
            $this->didYouMean($key, $catalogKeys, $root),
        ));
    }

    /* ---------------- host reads (each degrades, never throws) ---------------- */

    /**
     * Resolve the flow's gate from the container and read its state. Returns the {@see Throwable} when
     * resolving or reading it throws, so {@see classify()} can turn it into a `gate-threw` finding
     * rather than letting it propagate out of the audit.
     */
    protected function gateState(FlowDeclaration $declaration): FlowState|Throwable
    {
        try {
            $gate = $this->app->make($declaration->gate);

            return $gate->state();
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * The declared entry route NAMES that are not registered in this host's route table. Degrades to
     * "none missing" when there is no router to ask — a host with no nav is a host where the route check
     * cannot speak, not one where every flow is broken.
     *
     * @return list<string>
     */
    protected function missingRoutes(FlowDeclaration $declaration): array
    {
        if ($declaration->routes === []) {
            return [];
        }

        try {
            $router = $this->app->make('router');
        } catch (Throwable) {
            return [];
        }

        if (! is_object($router) || ! method_exists($router, 'has')) {
            return [];
        }

        return array_values(array_filter(
            $declaration->routes,
            static fn (string $name): bool => ! $router->has($name),
        ));
    }

    /**
     * The manifest for this host, read through {@see BeamManifest} (browser-journeys 01/07) — a missing
     * or empty BEAM.md yields an empty manifest whose {@see BeamManifest::deviates()} is always false,
     * never a throw.
     */
    protected function manifest(): BeamManifest
    {
        return BeamManifest::load();
    }

    /** Whether a composer package is installed in this host. Static, so it needs no host wiring. */
    protected function packageInstalled(string $package): bool
    {
        try {
            return InstalledVersions::isInstalled($package);
        } catch (Throwable) {
            return false;
        }
    }

    /* ---------------- registrants ---------------- */

    /**
     * The candidate registrant set: the registrant of every declared key. Under Supersede a declared
     * key resolves to its one live winner, so {@see FlowCatalog::registrantOf()} answers that winner,
     * and a displaced registrant is recovered from {@see FlowCatalog::superseded()} where the
     * supersession is reported. This universe is still built over every declared key so that a package
     * whose only visible keys are its OWN (its re-declared key having lost) still appears — it declared
     * at least one exclusive key — which is what lets {@see registrantsAt()} name the winner at a shared
     * key by walking {@see FlowCatalog::keysBy()} from the other direction.
     *
     * @param  list<string>  $absoluteKeys
     * @return list<string>
     */
    protected function registrantUniverse(array $absoluteKeys): array
    {
        $registrants = [];

        foreach ($absoluteKeys as $key) {
            $by = $this->catalog->registrantOf($key);
            if ($by !== null) {
                $registrants[$by] = true;
            }
        }

        return array_keys($registrants);
    }

    /**
     * Every registrant that declared `$absoluteKey`, recovered by walking {@see FlowCatalog::keysBy()}
     * over the candidate universe (keysBy answers ABSOLUTE keys, so the comparison is absolute).
     * Rendered as a comma list for the finding.
     *
     * @param  list<string>  $registrants
     */
    protected function registrantsAt(string $absoluteKey, array $registrants): string
    {
        $at = array_values(array_filter(
            $registrants,
            fn (string $registrant): bool => in_array(
                $absoluteKey,
                array_map('strval', $this->catalog->keysBy($registrant)),
                true,
            ),
        ));

        return $at === [] ? '(registrant unrecorded)' : implode(', ', $at);
    }

    /* ---------------- rendering ---------------- */

    /**
     * The catalog's own root, derived from one absolute/relative pair rather than hardcoded. `account`
     * stored at `beam.flows.account` yields `beam.flows`; a flow stored exactly at the root (no relative
     * remainder) yields the whole absolute key.
     */
    /**
     * The catalog's own declared root (`beam.flows`), read off its {@see IsRegistry} attribute rather
     * than hardcoded, so a relative key can be recovered from the absolute one `keys()` answers. `''`
     * when the attribute cannot be read — a miss degrades to treating keys as already-relative.
     */
    protected function catalogRoot(): string
    {
        try {
            $attributes = (new \ReflectionClass($this->catalog))->getAttributes(IsRegistry::class);

            if ($attributes === []) {
                return '';
            }

            $root = $attributes[0]->newInstance()->root;

            return is_string($root) ? $root : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** Strip the catalog root off an absolute key, giving the relative key the caller declared. */
    protected function relativize(string $absolute, string $root): string
    {
        if ($root === '') {
            return $absolute;
        }

        $prefix = $root.'.';

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }

    /**
     * The did-you-mean clause for an unattributed key: Popcorn's nearest keys, kept to the ones that are
     * actually flow keys so the hint never points at another registry's keyspace. The query is rebuilt
     * absolute so {@see PopcornManager::suggest()} routes it to the flow catalog and scores against flow
     * keys (a relative needle against the catalog's absolute keys would never clear the distance
     * threshold); the answers are relativised back to the vocabulary the author wrote.
     *
     * @param  list<string>  $catalogKeys  relative flow keys
     */
    protected function didYouMean(string $key, array $catalogKeys, string $root): string
    {
        try {
            $near = $this->popcorn->suggest($root === '' ? $key : $root.'.'.$key);
        } catch (Throwable) {
            $near = [];
        }

        $prefix = $root === '' ? '' : $root.'.';
        $relative = array_values(array_intersect(
            array_map(
                static fn (string $k): string => $prefix !== '' && str_starts_with($k, $prefix)
                    ? substr($k, strlen($prefix))
                    : $k,
                $near,
            ),
            $catalogKeys,
        ));

        return $relative === [] ? '' : ' (did you mean '.implode(', ', array_map(
            static fn (string $k): string => "`{$k}`",
            $relative,
        )).'?)';
    }

    /**
     * @param  list<array{abs: string, rel: string, declarations: list<FlowDeclaration>}>  $flows
     */
    protected function summary(array $flows): Finding
    {
        return Finding::pass(self::CHECK, sprintf(
            'Classified %d declared flow%s against this host (gates resolved, BEAM.md Deviations read).',
            count($flows),
            count($flows) === 1 ? '' : 's',
        ));
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
