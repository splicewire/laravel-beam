<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Database\Eloquent\Relations\Relation;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Threads\Models\Thread;
use Symfony\Component\Finder\Finder;

/**
 * Every Eloquent model a FAMILY PACKAGE ships must have its morph alias registered by that
 * package's OWN service provider — not by the host composing it.
 *
 * ## Why the host declaring them is the defect
 * splicewire-app's `AppServiceProvider` used to carry 24 aliases, 22 of them for models owned by
 * `splicewire/tower`, `laravel-satellite-*` and `laravel-beam-*`. That inverts the dependency: a
 * package ships a polymorphic model, but a *host* has to know its wire identifier for the model to
 * resolve. Every new host re-derives the same 22 lines, and getting one wrong fails SILENTLY —
 * Eloquent just writes the FQCN into the `*_type` column and moves on. There is no error, only a
 * column full of `Splicewire\Tower\Models\Flag` where `flag` belonged.
 *
 * Two concrete consequences, both live in this estate:
 *  1. **Permission tokens leak the FQCN.** The alias IS the token prefix — `PermissionNamer` reads
 *     `getMorphClass()` (ADR-0118) — so an unaliased policied model mints
 *     `splicewirebeammarketmodelssellerrepoauthorization.view`. That exact leak shipped.
 *  2. **Lineage becomes rename-brittle.** `EloquentVersionStore` writes
 *     `versionable_type = $model->getMorphClass()` and `Version::versionable()` reads it back
 *     through a `morphTo()`; `EloquentAwaitingStore` does the same with `subject_type`. A stored
 *     FQCN means a class rename orphans every historical row — the failure the durable-token
 *     convention exists to prevent (cf. beam-particle-rename T03).
 *
 * ## The two findings this raises
 *  - **`morph-alias.missing`** — the model is in no morph map and pins no alias of its own. It
 *    writes its FQCN. This is the common case and the one that costs.
 *  - **`morph-alias.write-side-only`** — the model OVERRIDES `getMorphClass()` to pin a token, but
 *    nothing registers the reverse entry. Half a registration: the write side is durable, but
 *    `morphTo()` cannot resolve the token back to a class, and the reverse lookup several packages
 *    perform — `array_search($class, Relation::$morphMap)` in `TextEmbeddingsService`,
 *    `CompleteRequestLogListener`, `ReplayNotificationStatus` — silently misses it. `beam_schema`
 *    and `beam_submission` sat in exactly this state.
 *
 * ## Advisory, not a gate — deliberately, and unlike {@see UndescribedRegistryAudit}
 * Findings are `warn()`, and the audit is registered WITHOUT `gate: true`. The sibling registry
 * audit can afford to block because it scopes itself to the index's own membership; this one is
 * scoped to *every* Eloquent model a family package ships, which is a much larger set that includes
 * plenty of models legitimately never used polymorphically (timeline's `Clip`/`Track`, a pivot, a
 * lookup table). A gate over that set would hard-block every host in the fleet on day one and be
 * switched off within the hour — the same reasoning that made the registry audit ratchet.
 *
 * So this reports a backlog you burn down, and the burn-down is cheap: one `Relation::morphMap()`
 * line in the owning provider clears a finding.
 *
 * ⚠️ **Advisory PERMANENTLY, as of ADR-0118 decision 6 (2026-09-01).** This slot used to say "promote to
 * `gate: true` once the backlog is clear", and that is now withdrawn for three measured reasons. The flag
 * only changes an EXIT CODE (`DoctorRunner:52-61`), and since every finding here is `warn()` while the
 * default floor is `fail`, flipping it is **inert** — it would ship a claim of enforcement that enforces
 * nothing, which is this estate's most expensive shape. At `--floor=warn` it is worse than inert: 92
 * findings today across 30+ packages, most of them models legitimately never polymorphic, so it hard-fails
 * every host at once. And whether a model is aliased HERE is a fact about the host, which by the estate's
 * standing rule is an advisory finding and never a throw.
 *
 * ADR-0118 decision 6 moved the ENFORCEMENT's home — from the flagship's `PolicyModelsAreAliasedTest`,
 * which cannot fail a package author, to this package-tier audit — not this audit's severity. That
 * decision is satisfied by what is already on disk: all five of the policy leaks it names are inside the
 * findings above.
 *
 * A host that wants coverage to block registers this class in its OWN manifest with `gate: true` and runs
 * `--floor=warn`. That is the estate's standard escape hatch and is documented the same way on six sibling
 * audits ({@see FilterablePromiseAudit}, {@see ListedResourceDisplacementAudit}, {@see SupersededDeclarationAudit}).
 *
 * ## Honesty about reach — the same caveat {@see AuditScanPaths} carries
 * Discovery walks `vendor/composer/installed.json` for family packages, so this audits WHAT THE HOST
 * COMPOSES, not the fleet. A package not installed here contributes nothing here and must be audited
 * from a host that composes it (or by its own package-local tooling). It is a host-scoped census, not
 * a fleet-wide one, and it will under-report by exactly the packages you did not install.
 */
class MorphAliasCoverageAudit implements DoctorAudit
{
    /** Vendor prefixes that count as "family" — a package we own and can therefore hold to this rule. */
    protected const FAMILY_VENDORS = ['splicewire/', 'rushing/', 'schemastud/'];

    /**
     * Models exempt by a RECORDED decision, keyed class-string => the reason. An exemption belongs
     * here only when someone argued for it in writing; "we haven't got to it" is a finding, not an
     * exemption.
     *
     * Lunar's models are the standing case: aliasing them would touch Lunar's own
     * `ModelManifest::morphMap()`, a foreign surface this estate does not own (beam-market ADR-0193).
     * They are not family-vendored so they never reach the scan, but the rule is stated here because
     * the next foreign-model question should land in this array rather than in a silent skip.
     *
     * The standing entries are the two lower-tier `Thread` variants. Three sibling classes ride the
     * SAME `beam_threads` row — all three resolve `Beam::tableFor('beam.threads.tables.threads')` —
     * and each pins `'thread'` from `getMorphClass()`. That is the design, not a collision: the alias
     * names the thread PARTICLE, and exactly ONE read-side entry exists, owned by the highest tier the
     * host composes (`Splicewire\Tower\Models\Thread`, registered by `TowerServiceProvider`). The
     * lower-tier variants keep a write-side pin ONLY, deliberately — `Embed::getMorphClass()` returns
     * `'embed'` for its permission token, so `Embed::versionable()` re-resolves the row as
     * beam-embed's `Thread` purely to get the `'thread'` morph back for its version lineage. Registering
     * a read-side entry for either would fight tower's for the same key.
     *
     * Verified deliberate, not vestigial: beam-embed's variant was CREATED by the TH-07/TH-08 phase-5
     * migration that retired the old `config('embed.base_model')` host seam, and the behaviour is
     * pinned by a test (`tower/tests/Feature/Embed/EmbedVersionsApiTest.php` asserts the morph types
     * are exactly `['thread']`).
     *
     * @var array<class-string, string>
     */
    protected const EXEMPT = [
        Thread::class => 'Tier variant of the `thread` particle; '
            .'tower owns the single read-side entry. Write-side pin only, by design.',
        \Splicewire\Beam\Embed\Models\Thread::class => 'Tier variant of the `thread` particle; exists so '
            .'Embed (whose own morph is `embed`) can reach its `thread` version lineage. Write-side pin only.',
    ];

    public function __construct(protected AttributedClassScanner $scanner) {}

    /** @return list<Finding> */
    public function run(): array
    {
        // class-string => alias. Relation::$morphMap is alias => class, and a class may legitimately
        // appear once; flipping is safe and gives the lookup this audit actually needs.
        $aliasedClasses = array_flip(Relation::$morphMap);

        $findings = [];

        foreach ($this->familyModelClasses() as $package => $models) {
            foreach ($models as $class => $source) {
                if (isset(static::EXEMPT[$class]) || isset($aliasedClasses[$class])) {
                    continue;
                }

                $pinned = $this->pinnedMorphClass($source);

                $findings[] = $pinned !== null
                    ? Finding::warn(
                        'morph-alias.write-side-only',
                        "{$package}: {$class} pins '{$pinned}' from its own getMorphClass() but nothing "
                        ."registers the reverse entry. Add \"'{$pinned}' => {$this->shortName($class)}::class\" "
                        ."to {$package}'s provider so morphTo() and array_search(\$class, Relation::\$morphMap) "
                        .'can resolve it back.'
                    )
                    : Finding::warn(
                        'morph-alias.missing',
                        "{$package}: {$class} has no morph alias, so it writes its FQCN into every "
                        .'polymorphic *_type column and into its permission-token prefix (ADR-0118). '
                        ."Register one additively from {$package}'s own packageBooted(): "
                        ."Relation::morphMap(['{$this->suggestAlias($class)}' => {$this->shortName($class)}::class])."
                    );
            }
        }

        if ($findings === []) {
            return [Finding::pass(
                'morph-alias.coverage',
                'Every Eloquent model shipped by an installed family package has its morph alias '
                .'registered by its own provider.'
            )];
        }

        return $findings;
    }

    /**
     * Family-package models, grouped by package name: `[package => [class-string => source]]`.
     * The source text rides along so {@see self::pinnedMorphClass()} does not re-read the file.
     *
     * @return array<string, array<class-string, string>>
     */
    protected function familyModelClasses(): array
    {
        $out = [];

        foreach ($this->familyPackageSourceDirs() as $package => $dirs) {
            $models = [];

            foreach ($dirs as $dir) {
                $models += $this->modelsIn($dir);
            }

            if ($models !== []) {
                $out[$package] = $models;
            }
        }

        return $out;
    }

    /**
     * Concrete Eloquent models under a directory, as `[class-string => source]`. Abstract bases are
     * skipped — an abstract model has no rows and therefore no `*_type` column to write into; its
     * concrete children are scanned on their own and are where the alias belongs.
     *
     * @return array<class-string, string>
     */
    protected function modelsIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $found = [];

        foreach ((new Finder)->files()->name('*.php')->in($dir) as $file) {
            $source = (string) file_get_contents($file->getRealPath());

            if (! $this->declaresEloquentModel($source)) {
                continue;
            }

            $class = $this->scanner->classNameFromFile($file->getRealPath());

            if ($class !== null) {
                $found[$class] = $source;
            }
        }

        return $found;
    }

    /**
     * Whether a file declares a concrete Eloquent model, decided from SOURCE — never by loading it.
     *
     * ## Why this is static analysis and not reflection
     * The obvious implementation is `class_exists($class) && (new ReflectionClass($class))
     * ->isSubclassOf(Model::class)`. It is not usable here, because `class_exists()` AUTOLOADS, and
     * autoloading is not a safe operation to perform over a whole vendor tree:
     *
     *  - a class whose parent is a dev-only dep (a package mapping a test root, parents in
     *    `orchestra/testbench`) raises an `Error`;
     *  - worse, a class whose method signature is incompatible with its parent's is a COMPILE-time
     *    fatal that no `try`/`catch` can contain — it kills the process. This estate has at least one
     *    (`Splicewire\Tower\Policies\ModelStatusPolicy::update()` vs `BaseModelPolicy::update()`),
     *    and an audit must not be the thing that discovers it by dying.
     *
     * An audit is a diagnostic; it has to survive a codebase that is already broken, which is
     * precisely the codebase it will be run against. So: read the text, load nothing.
     *
     * The trade-off is honest — a model extending a family base class several hops from `Model`
     * (rather than `Model`/`Pivot` directly) is not recognised and is silently skipped. That under-
     * reports; it never false-positives, which is the right direction for an advisory backlog.
     */
    protected function declaresEloquentModel(string $source): bool
    {
        if (preg_match('/^\s*abstract\s+class\s/m', $source)) {
            return false;
        }

        // `extends Model`, `extends Pivot`, or either fully qualified. The short forms are what the
        // estate writes (every model imports `Illuminate\Database\Eloquent\Model`).
        return (bool) preg_match(
            '/^\s*(?:final\s+|readonly\s+)*class\s+\w+\s+extends\s+(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\)?(?:Model|Pivot)\b/m',
            $source
        );
    }

    /**
     * The alias a model pins from its OWN `getMorphClass()` override, or null when it has none.
     *
     * Read from source for the same reason as {@see self::declaresEloquentModel()} — instantiating
     * the model (which is how Laravel itself reads a morph class) would mean autoloading it. Matches
     * the single-`return`-literal shape every override in this estate uses; an override computing its
     * token dynamically reads as "no pin", which is the safe direction.
     */
    protected function pinnedMorphClass(string $source): ?string
    {
        return preg_match(
            '/function\s+getMorphClass\s*\([^)]*\)\s*:?\s*\w*\s*\{\s*return\s+([\'"])(.+?)\1\s*;/s',
            $source,
            $m
        ) ? $m[2] : null;
    }

    /**
     * Family package name => its source dirs, read from composer's installed manifest.
     *
     * @return array<string, list<string>>
     */
    protected function familyPackageSourceDirs(): array
    {
        $manifest = base_path('vendor/composer/installed.json');

        if (! is_file($manifest)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        // Composer 2 nests under `packages`; Composer 1 was a bare list. Handle both rather than
        // silently returning nothing on the older shape.
        $packages = $decoded['packages'] ?? $decoded ?? [];

        if (! is_array($packages)) {
            return [];
        }

        $vendorDir = base_path('vendor');
        $out = [];

        foreach ($packages as $package) {
            $name = $package['name'] ?? null;

            if (! is_string($name) || ! $this->isFamily($name)) {
                continue;
            }

            $dirs = [];

            // PSR-4 roots are where a package's classes actually live; a package may declare several.
            // Only the `autoload` block — never `autoload-dev`, whose roots are fixtures and stubs.
            foreach (($package['autoload']['psr-4'] ?? []) as $paths) {
                foreach ((array) $paths as $relative) {
                    $relative = trim($relative, '/');

                    // A test/fixture root that leaked into `autoload` proper (several family packages
                    // map one). A test double is never a live model, so an alias finding against one
                    // is pure noise — and its parents are dev-only deps that may not even be installed.
                    if (preg_match('#(^|/)(tests?|fixtures?|stubs?|database)(/|$)#i', $relative)) {
                        continue;
                    }

                    $dirs[] = $vendorDir.'/'.$name.'/'.$relative;
                }
            }

            if ($dirs !== []) {
                $out[$name] = $dirs;
            }
        }

        return $out;
    }

    protected function isFamily(string $package): bool
    {
        foreach (static::FAMILY_VENDORS as $vendor) {
            if (str_starts_with($package, $vendor)) {
                return true;
            }
        }

        return false;
    }

    protected function shortName(string $class): string
    {
        return ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);
    }

    /**
     * The alias this estate's convention would give a model: snake_case of the class's short name.
     * Matches every alias already in the map (`FragmentUrlBatch` => `fragment_url_batch`,
     * `RunnerTransform` => `runner_transform`, `ModelStatus` => `model_status`). A suggestion only —
     * the owning package picks the final token, and once picked it is durable, because it is stored
     * in every polymorphic column and renaming it is an estate-wide data migration.
     */
    protected function suggestAlias(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $this->shortName($class)));
    }
}
