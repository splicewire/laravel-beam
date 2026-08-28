<?php

namespace Splicewire\Beam\Install;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RelativeUriKey;

/**
 * The beam-install self-registration manifest (beam-write-pipeline ticket 08). A container SINGLETON
 * every beam-* package pushes its own {@see InstallStep} into — from its OWN service provider — so
 * `splicewire:beam:install` sets up the whole stack from ONE command instead of a per-package installer each.
 *
 * The direction is load-bearing: consumers register DOWN into beam's manifest; **beam-core never learns
 * a consumer's name** (it just iterates whatever registered). That keeps the dependency graph acyclic —
 * beam depends on nothing above it, yet the whole family installs together. Steps run core-first (by
 * {@see InstallStep::$order}, then registration order — `usort` is stable), so the substrate lands before
 * anything that composes it.
 *
 * ## `$order` is migration order, not just cosmetics
 *
 * Publishing STAMPS a migration at publish time, so the order steps run here IS the order the published
 * migrations sort in on a greenfield install. A package shipping an ALTER against a table ANOTHER
 * package creates must therefore register a higher `$order` than the package that creates it.
 *
 * The rule, the current order tiers, and the two things that are NOT the fix live in ONE place —
 * `docs/agents/migration-publish-ordering.convention.md`. Deliberately not restated here: a rule
 * written in two places is a rule that drifts.
 */
#[IsRegistry(
    root: 'beam.install.steps',
    of: 'package install steps (publish tags + migrate flag), run core-first',
    arity: RegistryArity::RunAll,
    entryType: InstallStep::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Keyed by package name and idempotent by design — a provider that boots twice (test harness) '
        .'must not double-publish, so re-registering replaces rather than accumulating.',
    order: 1,
)]
/**
 * @implements Registry<InstallStep>
 */
class BeamInstallManifest implements Registry
{
    /** @var BasicRegistry<InstallStep> */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register a package's install step. Idempotent per package name — re-registering replaces, so a
     * provider that boots twice (test harness) doesn't double-publish.
     *
     * @param  list<string>  $publishTags
     */
    public function register(
        RegistryKey|string $package,
        mixed $publishTags = [],
        ?string $by = null,
        ?string $ability = null,
        bool $migrates = false,
        int $order = 100,
        ?string $note = null,
    ): static {
        // The key is a COMPOSER PACKAGE NAME, so `/` makes it illegal as a bare `Key` — 58 D5's
        // ruling for `BeamExtensionInstallManifest` applies verbatim: `RelativeUriKey`, coordinate
        // preserved as spelled. Every parameter keeps its name because every call site in the estate
        // passes by name; the sweep that established that covered every package `src`/`tests`, every
        // Herd host and every starter, not a three-package sample.
        $this->store->register(
            $package instanceof RegistryKey ? $package : RelativeUriKey::of(static::coordinateOf($package)),
            new InstallStep((string) $package, is_array($publishTags) ? $publishTags : [], $migrates, $order, $note),
            $by,
            $ability,
        );

        return $this;
    }

    /**
     * The composer coordinate inside a package label.
     *
     * ⚠️ This field carries a HUMAN LABEL, not just a coordinate: beam-core registers itself as
     * `splicewire/laravel-beam (core)`, which is legal as neither a `Key` nor a `RelativeUriKey` —
     * the space and parentheses are outside the charset. Identity and presentation have been
     * conflated in this one field all along, and `MigrationOrderingAudit::installPath()` already
     * carries the same strip (`preg_replace('/\s*\(.*\)$/', '', $package)`) to look a package up.
     *
     * The KEY takes the coordinate; the `InstallStep` keeps the label verbatim, so nothing a human
     * reads changes. Fixing the conflation properly means a separate `$label` on the step, which is
     * a wider change than this migration should make on its own.
     */
    protected static function coordinateOf(string $package): string
    {
        return trim((string) preg_replace('/\s*\(.*\)$/', '', $package));
    }

    /** @return list<InstallStep> */
    protected function allSteps(): array
    {
        return array_values(array_map(
            fn (RegistryKey $key): mixed => $this->store->resolve($key),
            $this->store->keys(),
        ));
    }

    /**
     * All registered steps, ordered core-first (ascending {@see InstallStep::$order}, ties keeping
     * registration order — `usort` has been stable since PHP 8.0).
     *
     * @return list<InstallStep>
     */
    public function steps(): array
    {
        $steps = $this->allSteps();
        usort($steps, static fn (InstallStep $a, InstallStep $b): int => $a->order <=> $b->order);

        return $steps;
    }

    /** Whether any registered step contributes migrations (so `splicewire:beam:install` migrates once at the end). */
    public function migrates(): bool
    {
        foreach ($this->allSteps() as $step) {
            if ($step->migrates) {
                return true;
            }
        }

        return false;
    }

    /* ---------------- Registry contract ---------------- */

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return array<string, mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }
}