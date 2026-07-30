<?php

declare(strict_types=1);

namespace Splicewire\Beam\Install;

/**
 * One beam-* package's install step in the {@see BeamInstallManifest} (beam-write-pipeline ticket 08):
 * the publish tags to run and whether the package contributes migrations, plus an ordering hint so
 * `beam:install` runs core-first then consumers.
 */
final class InstallStep
{
    /**
     * @param  string  $package  the registering package's name (for operator output only — beam never
     *                           branches on it; a consumer names ITSELF)
     * @param  list<string>  $publishTags  the `vendor:publish` tags to run for this package
     * @param  bool  $migrates  whether this package contributes migrations (a single migrate runs after all publishes)
     * @param  int  $order  lower runs first; beam-core registers at 0 (core-first), consumers default to 100
     */
    public function __construct(
        public readonly string $package,
        public readonly array $publishTags = [],
        public readonly bool $migrates = false,
        public readonly int $order = 100,
    ) {}
}
