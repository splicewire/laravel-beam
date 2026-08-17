<?php

namespace Splicewire\Beam\Install;

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
class BeamInstallManifest
{
    /** @var list<InstallStep> */
    private array $steps = [];

    /**
     * Register a package's install step. Idempotent per package name — re-registering replaces, so a
     * provider that boots twice (test harness) doesn't double-publish.
     *
     * @param  list<string>  $publishTags
     */
    public function register(string $package, array $publishTags = [], bool $migrates = false, int $order = 100, ?string $note = null): void
    {
        $this->steps = array_values(array_filter(
            $this->steps,
            static fn (InstallStep $step): bool => $step->package !== $package,
        ));

        $this->steps[] = new InstallStep($package, $publishTags, $migrates, $order, $note);
    }

    /**
     * All registered steps, ordered core-first (ascending {@see InstallStep::$order}, ties keeping
     * registration order — `usort` has been stable since PHP 8.0).
     *
     * @return list<InstallStep>
     */
    public function steps(): array
    {
        $steps = $this->steps;
        usort($steps, static fn (InstallStep $a, InstallStep $b): int => $a->order <=> $b->order);

        return $steps;
    }

    /** Whether any registered step contributes migrations (so `splicewire:beam:install` migrates once at the end). */
    public function migrates(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->migrates) {
                return true;
            }
        }

        return false;
    }
}
