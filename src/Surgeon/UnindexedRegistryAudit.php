<?php

namespace Splicewire\Beam\Surgeon;

use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Laddered;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;
use Symfony\Component\Finder\Finder;

/**
 * **Declared on disk, absent from the index at this host** — registry-kernel ticket 73 step 1.
 *
 * This is the check for the hole that produced the ticket. On 2026-08-28 `splicewire/laravel-beam`
 * migrated `CapabilityRegistry`, `BeamDoctorManifest`, `BeamInstallManifest`, `BeamSeedManifest` and
 * `SchemaSources` onto the contract. All five declared `#[IsRegistry]`, all five implemented
 * {@see Registry}, and for **three days** none of them was in the index — `beam.capabilities`,
 * `beam.doctor.audits`, `beam.install.steps`, `beam.seed.steps` and `schemas.sources` were absent from
 * `popcorn:registries` and unroutable through `RegistryIndex::routeTo()`. Five instruments read green
 * over it: the conformance command said `conforming` for all five, {@see UndescribedRegistryAudit} —
 * which asks the DECLARATION question, exactly as its own docblock says — passed over all five,
 * `surgeon:audit` said `PASS`, and the suite was green at 1793.
 *
 * **Nothing was wrong with any of those five instruments.** Not one of them was asking this question,
 * because until 73 there were two authoring acts — put the attribute on the class, *and* hand-write
 * `RegistryIndex::describe(...)` in a provider's `boot()` — and every check in the estate asked about
 * the first. The second is a line ~55 call sites long that a human types, and a line a human types is a
 * line a human forgets.
 *
 * ## What it does NOT do, and where it is going
 *
 * It does not close the gap by making the second act automatic; ticket 73's D2 rules that the membership
 * list is BAKED at build time from a static source scan and resolved LAZILY on first `routeTo()`. This
 * audit is built FIRST and is not scaffolding for that: **it is what makes the baked list safe.** The
 * standing objection to a cache is that staleness silently drops a registry — true only if nothing
 * checks, and this is the check. So it earns its place twice: it closes the hole in days rather than
 * weeks, and it becomes the staleness detector step 2 needs.
 *
 * ## ADVISORY, never a gate
 *
 * Index membership is a **composition fact**. A registry declared in a package this host does not
 * install cannot be described here, and the same declaration is a finding at one host and correct at
 * another. That is the estate's own rule — *a check whose answer depends on the host must not throw* —
 * and the two gates that already exist in this effort ({@see UndescribedRegistryAudit},
 * {@see RegistryConformanceAudit}) both gate questions a declaration's author could have answered alone.
 * This one cannot be. It is registered with no `gate:` flag and emits {@see Finding::warn()}.
 *
 * ## What it excludes, and why each exclusion is somebody else's check rather than a hole
 *
 *   - **A class that does not implement {@see Registry}.** `describe()` takes a conforming registry, so
 *     such a class *cannot* be described and reporting it here would be an unactionable finding. It is
 *     already owned, and gated, by {@see RegistryConformanceAudit}'s `contract` check. The live example
 *     is `Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry`, which declares `schemas.overlays` and
 *     implements nothing. **The count is stated in this audit's own output** so a pass here is not read
 *     as coverage it does not have.
 *   - **Rungs — a `Laddered` inner tier.** Ticket 33 D8 ruled that a ladder over registries it does not
 *     own (position 3: `implements Laddered` and nothing else) has no root and no index membership by
 *     design. Such a class carries no `#[IsRegistry]`, so it is out of this population before the
 *     exclusion is reached; the test is kept anyway and stated, because a *silent* exclusion and an
 *     absent population read identically.
 *   - **Abstract classes, interfaces and traits** carrying the attribute. Nothing can describe an
 *     instance of one.
 *
 * ## ⚠️ It must report its own BLINDNESS rather than a clean estate
 *
 * Every failure mode of a filesystem scan produces an empty list, and an empty list of findings is
 * exactly what a healthy estate produces. So the two are separated explicitly, on
 * {@see Finding::inconclusive()} — the mechanism api-surface-coherence 124 built for precisely this, and
 * a better instrument than the boolean {@see UndescribedRegistryAudit::detectionAvailable()} that
 * preceded it. {@see detectionAvailable()} survives as the same predicate for callers that want it.
 *
 * Three blindness conditions, and one partial:
 *
 *   - **no scanner / no `symfony/finder`** — nothing to walk the tree with;
 *   - **no scan roots** — {@see HostScanRoots::resolve()} returned nothing, i.e. the host has no `app`
 *     or `src` and vendors no family package;
 *   - **an index holding only its own zero-segment root** — nothing anywhere has described, so every
 *     declared class would be reported and the report would be about the harness rather than the estate.
 *     Emitted as `Warn` **and** inconclusive, because an index that never filled is itself worth saying
 *     out loud;
 *   - **partial: classes the scan could not autoload.** `class_exists()` raises rather than returning
 *     false when a parent is absent, which is ordinary inside a family package's `src/Testing/` at a
 *     host that lacks its `require-dev`. `AttributedClassScanner::unloadable()` records them (fixed in
 *     `php-popcorn` for this audit — before that the scanner *died* on one such file at
 *     `~/Herd/splicewire-app`), and they are reported as a separate reach warning. A class the scanner
 *     could not read is a class this audit cannot vouch for.
 *
 * ## ⚠️ A described-but-EMPTY root is NOT a finding
 *
 * The question is membership, not population. `popcorn.invocables` is described at
 * `~/Herd/splicewire-app` and holds **zero** entries, filtered and unfiltered, because that host's
 * invocables live on the `CompositionInvocableRegistry` subclass under root `composition`. That is
 * honest, not broken, and an audit that read emptiness as absence would file it every run and be
 * switched off. Membership is tested against the index's declared ROOTS.
 *
 * ## Static reach vs runtime reach — measured once, deliberately, 2026-08-31
 *
 * The scan is the instrument, so its recall is a fact about this audit and had to be measured rather
 * than assumed (73's *"does the static scan actually reach all 83?"*). At `~/Herd/splicewire-app`, over
 * 83 scan roots and 4,995 PHP files: **static scan 85 declared classes; runtime container-binding walk
 * 83.** The two disagreements both resolve in the scan's favour:
 *
 *   - the one class the runtime walk had that the scan did not is
 *     `Splicewire\Tower\Compliance\Corpus\CorpusStreamRegistry`, a `class_alias` of the
 *     `…\Determination\Corpus\…` class the scan *did* find — one symbol under two names, not a miss;
 *   - the scan found two the binding walk could not, because they are **never container-bound**:
 *     `InMemoryOverlayRegistry` and `Blockdoc\Schema\NodeSchema`.
 *
 * So static reach is a strict superset of runtime reach here, which is the corroboration 73's D2 needed
 * before committing the baked list to a source scan.
 *
 * ⚠️ **The reconciliation probe cost 4.4 s and THIS AUDIT costs 0.5–0.9 s** (measured twice, cold, at the
 * flagship). The probe was slower because it built one `Finder` per scan root; quoting its figure here
 * would have published a number for a shape this class does not have. Either way the point stands and is
 * D2's: seconds are fine for an audit and impossible at boot, which is the whole argument for paying the
 * scan here rather than on every request.
 */
class UnindexedRegistryAudit implements DoctorAudit
{
    public const CHECK = 'registry.unindexed';

    /** @var list<class-string>|null memoized scan result — see {@see scannedClasses()} */
    protected ?array $scanned = null;

    /** @var array<class-string, string> snapshotted beside the scan, because the scanner resets per walk */
    protected array $unloadable = [];

    /**
     * @param  list<string>  $scanRoots
     */
    public function __construct(
        protected RegistryIndex $index,
        protected array $scanRoots,
        protected ?AttributedClassScanner $scanner = null,
    ) {
        $this->scanner ??= class_exists(AttributedClassScanner::class) ? new AttributedClassScanner : null;
    }

    /**
     * Scoped to the whole host composition — every host source dir plus one root per family package.
     *
     * Deliberately NOT {@see UndescribedRegistryAudit::forIndex()}'s membership ratchet. That ratchet
     * exists to make a GATE survivable: only packages that already opted in are held to completeness.
     * This audit is advisory, and scoping it to membership would make it structurally unable to see the
     * defect it exists for — a package whose registries are declared and none of which reached the index
     * would be scoped out for having described nothing.
     */
    public static function forHost(RegistryIndex $index): self
    {
        return new self($index, HostScanRoots::resolve());
    }

    public function detectionAvailable(): bool
    {
        return $this->scanner !== null && class_exists(Finder::class) && $this->scanRoots !== [];
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if ($this->scanner === null || ! class_exists(Finder::class)) {
            return [Finding::inconclusive(self::CHECK, self::class.' could not look: `symfony/finder` or '
                .'`Rushing\Popcorn\Discovery\AttributedClassScanner` is not installed here, so no source was '
                .'scanned. This is not a statement that every declared registry is indexed.')];
        }

        if ($this->scanRoots === []) {
            return [Finding::inconclusive(self::CHECK, self::class.' could not look: HostScanRoots resolved '
                .'ZERO roots, so no source was scanned. This host has no `app` or `src` directory and vendors '
                .'no `rushing`/`schemastud`/`splicewire` package. This is not a statement that every declared '
                .'registry is indexed.')];
        }

        $described = $this->describedRoots();

        if ($described === ['']) {
            return [new Finding(DoctorStatus::Warn, self::CHECK, 'The registry index holds nothing but its '
                .'own zero-segment root: no package anywhere in this host has described a registry into it. '
                .'Every declared class would be reported as unindexed, which would be a statement about this '
                .'harness rather than about the estate, so nothing is reported. Check that '
                .'`Rushing\Popcorn\Laravel\PopcornServiceProvider` is registered.', conclusive: false)];
        }

        $findings = [];

        foreach ($this->unindexed() as $root => $class) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '`%s` declares the registry root `%s` and is NOT in this host\'s index, so `popcorn:registries` '
                    .'cannot show it and `RegistryIndex::routeTo(\'%s\')` returns null. Declaring is one act and '
                    .'describing is a second: add `$this->app->make(RegistryIndex::class)->describe($this->app'
                    .'->make(%s::class), by: self::class);` to the owning provider\'s `boot()`. Advisory — if the '
                    .'owning package is not meant to be composed here, this row is correct and expected.',
                $class,
                $root,
                $root,
                (new ReflectionClass($class))->getShortName(),
            ));
        }

        $this->scannedClasses();

        if ($this->unloadable !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                'REACH: %d class(es) under the scan roots could not be autoloaded, so this audit cannot vouch '
                    .'for them either way — %s. The usual cause is a family package\'s `src/Testing/` helper '
                    .'extending a `require-dev` parent the host does not install.',
                count($this->unloadable),
                implode(', ', array_keys($this->unloadable)),
            ));
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, sprintf(
            'Every registry declared under %d scan root(s) is described into this host\'s index — %d declared, '
                .'%d root(s) in the index. NOT covered: %d declared class(es) that do not implement `Registry` '
                .'and therefore cannot be described at all (owned and GATED by %s\'s `contract` check), and any '
                .'registry declared in a path no scan root names. A described root holding zero entries is not '
                .'a finding here — membership is the question, population is not.',
            count($this->scanRoots),
            count($this->declared()),
            count($this->describedRoots()),
            count($this->nonConforming()),
            RegistryConformanceAudit::class,
        ))];
    }

    /**
     * Declared root => class-string, for every in-population class the scan reached.
     *
     * @return array<string, class-string>
     */
    public function declared(): array
    {
        $found = [];

        foreach ($this->scannedClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if (! $this->inPopulation($reflection)) {
                continue;
            }

            $found[$this->declarationOf($reflection)->root] = $class;
        }

        ksort($found);

        return $found;
    }

    /**
     * Declared root => class-string, for the rows whose root no registry occupies in this host's index.
     *
     * @return array<string, class-string>
     */
    public function unindexed(): array
    {
        $described = $this->describedRoots();

        return array_filter(
            $this->declared(),
            fn (string $root): bool => ! in_array($root, $described, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Declared classes the scan reached that do NOT implement {@see Registry} — excluded from the
     * population above, counted here so the pass can say what it does not cover.
     *
     * @return array<string, class-string>
     */
    public function nonConforming(): array
    {
        $found = [];

        foreach ($this->scannedClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if ($reflection->implementsInterface(Registry::class)) {
                continue;
            }

            $found[$this->declarationOf($reflection)->root] = $class;
        }

        ksort($found);

        return $found;
    }

    /**
     * The population test, stated in one place so every exclusion above is one readable predicate.
     *
     * The {@see Laddered} clause is redundant today — position 3 carries no `#[IsRegistry]` and cannot
     * reach here — and is kept because 33 D8's exclusion should be legible at the place it applies
     * rather than inferred from a population that happens not to contain it.
     *
     * @param  ReflectionClass<object>  $class
     */
    protected function inPopulation(ReflectionClass $class): bool
    {
        if ($class->isAbstract() || $class->isInterface()) {
            return false;
        }

        if ($class->implementsInterface(Laddered::class) && ! $class->implementsInterface(Registry::class)) {
            return false;
        }

        return $class->implementsInterface(Registry::class);
    }

    /**
     * The scan, MEMOIZED — it walks 4,995 files across 83 roots at the flagship and costs 4.4 s, and
     * `run()` reads the result three ways. Three walks would be twelve seconds inside `surgeon:audit`
     * for one answer. The unloadable record is snapshotted with it, because
     * `AttributedClassScanner::unloadable()` resets per walk and would otherwise report the LAST scan.
     *
     * @return list<class-string>
     */
    protected function scannedClasses(): array
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }

        if ($this->scanner === null) {
            return $this->scanned = [];
        }

        $found = $this->scanner->scan($this->candidateFiles(), IsRegistry::class, instanceof: false);

        $this->unloadable = $this->scanner->unloadable();

        return $this->scanned = $found;
    }

    /**
     * The files worth AUTOLOADING: those whose source text names {@see IsRegistry} at all.
     *
     * ## ⚠️ This is not an optimisation. Without it the audit CRASHES a host.
     *
     * `AttributedClassScanner` reaches a class through `class_exists()`, which autoloads — so handing it
     * a family package's whole `src/` compiles thousands of unrelated classes, and compiling an arbitrary
     * class in a host that does not install its dependencies is not safe.
     *
     * **A missing PARENT and a missing TRAIT are not the same failure.** A missing parent raises a
     * catchable `Error`, which the scanner now swallows and records. A missing **trait** is an
     * `E_COMPILE_ERROR` raised while the class is being declared — **not catchable at all**, by anything,
     * anywhere. Measured 2026-08-31 at `~/Herd/fable`: the scan met
     * `rushing/laravel-surgeon`'s `Mcp\SurgeonMcpServer`, whose `use AuthorizesTools;` comes from
     * `rushing/laravel-mcp-registry` — absent at that host — and the process died with
     * `Trait "Rushing\McpRegistry\Concerns\AuthorizesTools" not found`. No `try` would have helped.
     *
     * A cheap `str_contains` over the file's source takes the flagship's autoload surface from ~5,000
     * classes to ~85 — the ones this audit is actually about — which is the only version of this scan
     * that is safe to run inside `surgeon:audit` at an arbitrary host.
     *
     * **Recall is preserved and was measured, not assumed.** At the flagship, filtered against an
     * unfiltered control over the same roots: **84 in-population either way, zero lost, zero gained** —
     * and 268 ms against 492 ms. Two differently-shaped instruments agreeing, which is the standard here;
     * a filter checked only against itself is one instrument. Matching the SHORT NAME rather than the FQCN
     * is what keeps an aliased import
     * (`use …\IsRegistry as Reg;` + `#[Reg(...)]`) in the population: the `use` line names it either way.
     *
     * @return list<string>
     */
    protected function candidateFiles(): array
    {
        $marker = (new ReflectionClass(IsRegistry::class))->getShortName();
        $files = [];

        foreach ($this->scanRoots as $root) {
            if (! is_dir($root)) {
                if (is_file($root) && str_contains((string) file_get_contents($root), $marker)) {
                    $files[] = $root;
                }

                continue;
            }

            foreach ((new Finder)->files()->name('*.php')->in($root) as $file) {
                $path = (string) $file->getRealPath();

                if (str_contains((string) file_get_contents($path), $marker)) {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * The roots the index actually holds, read UNFILTERED — membership is a structural question, and an
     * authorizer narrowing it would manufacture findings for registries the running actor merely cannot
     * see.
     *
     * @return list<string>
     */
    protected function describedRoots(): array
    {
        return array_map('strval', $this->index->unfiltered()->keys());
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    protected function declarationOf(ReflectionClass $class): IsRegistry
    {
        return $class->getAttributes(IsRegistry::class)[0]->newInstance();
    }
}
