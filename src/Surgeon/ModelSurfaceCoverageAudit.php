<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\MakeParticleResourceCommand;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Surgeon\Support\EloquentModelSource;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;

/**
 * **Which Eloquent models have no declared surface at all** — the question every audit in this package
 * is structurally unable to ask.
 *
 * ## Why {@see UndeclaredSurfaceAudit} cannot see this, which is the whole reason for a second audit
 *
 * `UndeclaredSurfaceAudit` computes from the **live route table** (`foreach (Route::getRoutes() …)`),
 * so it finds surfaces that EXIST and declare no shape. A model carrying no `#[ParticleResource]` has
 * no route: it never enters the route table, never reaches the committed artifact, and the ratchet over
 * that artifact is silent about it. Two different questions, and only the first was answered:
 *
 * | question | answered by |
 * |---|---|
 * | this endpoint crosses a boundary without declaring a shape | `UndeclaredSurfaceAudit` |
 * | this model has no declared surface at all | here |
 *
 * The gap bites an ADOPTING host hardest — an existing Laravel app arriving at beam has N models and
 * zero particle resources, and until now nothing told it where it stood.
 *
 * ## ⚠️ Advisory, permanently — and "every model should have a resource" is FALSE as stated
 *
 * Registered without `gate: true`, and every finding is `warn()`. Two independent reasons, and the
 * second is the one that has taken a host off the air:
 *
 *  1. **The premise is false by construction.** Pivots, lookup tables, models that are pure
 *     implementation detail behind an aggregate — all legitimately have no surface. A gate over that
 *     set hard-blocks every host on day one and is switched off within the hour, which is exactly the
 *     argument {@see MorphAliasCoverageAudit} already makes for the same shape of population and which
 *     ADR-0118 decision 6 settled there PERMANENTLY. This audit inherits that ruling rather than
 *     re-deriving it. So this reports a backlog to read, never a build to fail; there is deliberately
 *     no `--check` mode and no CI-failing path.
 *  2. **Whether a model is surfaced HERE is a fact about the host.** The estate's standing rule is that
 *     only what a declaration's author could have gotten right *without knowing which host would load
 *     it* may throw. A new catalog that threw at boot on a host-dependent registration took
 *     `~/Herd/tower` off the air entirely; this audit is built to that ruling from the first commit.
 *
 * A host that wants coverage to block registers this class in its OWN manifest with `gate: true` and
 * runs `--floor=warn` — the estate's standard escape hatch, documented the same way on six sibling
 * audits.
 *
 * ## Population, and why it is BOTH the host and the packages
 *
 * Roots come from {@see HostScanRoots::resolve()} — the host's own `app`/`src` plus one resolved root
 * per family package it composes. That is the shape {@see MorphTokenBypassAudit::scanRoots()} settled
 * for the same question one axis over (*"the host's own code can hand-assemble a morph value exactly as
 * a package can"*), and `HostScanRoots` is where it was centralized after three audits derived it
 * separately and two of them derived it wrong — a `RecursiveDirectoryIterator` handed
 * `vendor/splicewire` whole descends into none of the symlinks it contains and returns a confident
 * empty result. Reaching for a fourth private copy is the defect that class exists to end.
 *
 * The consequence is that this is a host-scoped census, not a fleet-wide one: it under-reports by
 * exactly the packages this host does not install. Which is, again, why it is advisory.
 *
 * ## Coverage subtracts MODELS, not registered resources
 *
 * The forward index is {@see ModelResourceIndex}, whose membership rule is *a resource appears iff its
 * backing declares {@see BacksModel}*. That distinction is load-bearing rather than pedantic: the
 * `backing:` slot is polymorphic, and a source-backed resource (`members`, `review-queue`) backs no
 * single model. Counting registration itself as coverage would silence every finding at a host whose
 * resources are source-backed — a green audit over an entirely unsurfaced estate.
 *
 * ## Clean and did-not-look are different answers here
 *
 * Four terminal readings, and only the first is a claim of health. The last two ride ALONGSIDE the real
 * findings rather than replacing them, so an incomplete census never hides a finding and a finding never
 * hides an incomplete census:
 *
 *  - **pass** — a non-empty population, fully named, fully reached, nothing uncovered. It states the
 *    counts it measured, because a pass that says only "all clear" cannot be told from one taken over
 *    two files.
 *  - **`model-surface.coverage`, inconclusive** — zero files scanned. Run from a package testbench there
 *    is no `app/` and nothing composed, and a `pass()` over an empty population is this estate's
 *    signature defect.
 *  - **`model-surface.unnamed`, inconclusive** — the UNKNOWN blind spot. Files whose text declares a
 *    model but whose tokens name no class; those models could not be checked either way.
 *  - **`model-surface.underreach`, inconclusive** — the KNOWN blind spot, measured rather than
 *    documented. The census reads `extends Model`/`extends Pivot` from source, so a model further from
 *    `Model` (`App\Models\User` through `Authenticatable`) is invisible to it. Every model a registered
 *    resource backs demonstrably exists, so the backed models the scan did not find are an exact lower
 *    bound on that gap — 9 against 137 seen at `~/Herd/splicewire-app`, 2026-09-04. An instrument that
 *    enumerates only its known blind spots reads as thorough exactly where it is weakest; this one puts
 *    a number on its own.
 */
class ModelSurfaceCoverageAudit implements DoctorAudit
{
    public const CHECK = 'model-surface.coverage';

    public const UNCOVERED = 'model-surface.uncovered';

    public const UNNAMED = 'model-surface.unnamed';

    public const UNDERREACH = 'model-surface.underreach';

    /**
     * The host's escape hatch, and it is deliberately at HOST tier rather than a package `const`.
     *
     * {@see MorphAliasCoverageAudit::EXEMPT} is the wrong prior art to copy here: it is a package
     * constant, so excusing a host's own deliberately-internal model would require a code change in
     * beam. The host-tier idiom is a config key, exactly as
     * {@see SdkHookMigrationAudit::forApp()} reads `beam.client.surgeon.sdk_hook_migration.exclude_dirs`.
     */
    public const EXCLUDE_KEY = 'beam.core.surgeon.model_surface_coverage.exclude_models';

    /**
     * @param  list<string>|null  $roots  null ⇒ {@see HostScanRoots::resolve()}. Injected only by tests
     *                                    and by a host wanting a narrower sweep.
     * @param  list<class-string>|null  $excludedModels  null ⇒ read from {@see EXCLUDE_KEY} at run time.
     *                                                   Read on RUN, never stamped at construction, so a
     *                                                   host configuring it later is honoured.
     */
    public function __construct(
        protected ModelResourceIndex $index,
        protected EloquentModelSource $source,
        protected ?array $roots = null,
        protected ?array $excludedModels = null,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $scan = $this->source->scan($this->roots ?? HostScanRoots::resolve());

        // ⚠️ An empty population is "nothing here", not "measured clean". Run from a package testbench
        // this scans NOTHING — there is no `app/` and no composed vendor tree — and a confident pass
        // over zero files reads identically to a real all-clear.
        if ($scan->filesScanned === 0) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'No PHP source was scanned across %d root(s): this host has no app/ or src/ directory and '
                .'composes no family packages, so there was no population to measure. Run this from a '
                .'composed host.',
                count($this->roots ?? HostScanRoots::resolve()),
            ))];
        }

        $covered = $this->index->all();
        $excluded = array_flip($this->excludedModels());

        $findings = [];

        foreach ($scan->models as $class => $path) {
            if (isset($covered[$class]) || isset($excluded[$class])) {
                continue;
            }

            $findings[] = Finding::warn(self::UNCOVERED, sprintf(
                '%s (%s) declares no #[ParticleResource], so it has no REST transport, no generated '
                ."TypeScript and no client SDK — and no audit here can see it, because it has no route.\n"
                ."    %s\n"
                .'Or, if it is deliberately internal (a pivot, a lookup table, an aggregate detail), add it '
                .'to `%s` and this stops asking.',
                $class,
                $this->relative($path),
                $this->remedyFor($class),
                self::EXCLUDE_KEY,
            ));
        }

        // The KNOWN blind spot, measured instead of merely documented. The census recognises `extends
        // Model` / `extends Pivot` from source text, so a model several hops from `Model` — `App\Models\User`
        // through `Authenticatable`, a token model through Sanctum's — is invisible to it. Every model a
        // registered resource BACKS is a model that demonstrably exists, so the ones the scan did not
        // find are a free, exact lower bound on that gap. Measured 2026-09-04 at `~/Herd/splicewire-app`:
        // 137 models seen, 9 backed models unseen.
        //
        // It can only cause UNDER-reporting, never a false finding — but "137 models, none missing" and
        // "137 models, and at least 9 more I cannot see" are different answers and must not print the same.
        $uncovered = count($findings);
        $unseen = array_values(array_diff(array_keys($covered), array_keys($scan->models)));

        if ($unseen !== []) {
            $findings[] = Finding::inconclusive(self::UNDERREACH, sprintf(
                '%d model(s) are backed by a registered resource yet were not found by the source census, '
                .'so it recognises fewer models than exist and its %d-model total is a floor. These are '
                .'covered and therefore not findings; the concern is the unknown number of UNCOVERED models '
                .'sharing their shape (a model extending a base class rather than Model/Pivot directly): %s',
                count($unseen),
                count($scan->models),
                implode(', ', $unseen),
            ));
        }

        // Reported ALONGSIDE the real findings, never instead of them, and never swallowed by them.
        if ($scan->unnamed !== []) {
            $findings[] = Finding::inconclusive(self::UNNAMED, sprintf(
                '%d file(s) declare an Eloquent model in their source text but name no class, so this run '
                .'could not decide whether they are surfaced. The %d model(s) counted are a floor, not a '
                .'total, and %d finding(s) above are likewise a floor: %s',
                count($scan->unnamed),
                count($scan->models),
                $uncovered,
                implode(', ', array_map($this->relative(...), $scan->unnamed)),
            ));
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d Eloquent model(s) across %d scanned file(s) either declare a #[ParticleResource] '
                .'or are excluded by `%s`.',
                count($scan->models),
                $scan->filesScanned,
                self::EXCLUDE_KEY,
            ))];
        }

        return $findings;
    }

    /**
     * The invocation that would actually fix the finding.
     *
     * ⚠️ `splicewire:beam:make:particle-resource --model=…` on its own **does not run**: the Data-class
     * name is a required ARGUMENT and `--model` merely overrides the model that name would otherwise be
     * derived from ({@see MakeParticleResourceCommand::handle()}). A finding
     * naming a command that errors is worse than one naming none, so the argument is emitted and the
     * FQCN is single-quoted — an unquoted `App\Models\Foo` loses its backslashes in most shells.
     */
    public function remedyFor(string $class): string
    {
        return sprintf(
            "splicewire:beam:make:particle-resource %sData --model='%s'",
            $this->shortName($class),
            $class,
        );
    }

    /** @return list<class-string> */
    protected function excludedModels(): array
    {
        if ($this->excludedModels !== null) {
            return $this->excludedModels;
        }

        return array_values(array_filter(
            (array) config(self::EXCLUDE_KEY, []),
            is_string(...),
        ));
    }

    protected function shortName(string $class): string
    {
        return ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);
    }

    protected function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
