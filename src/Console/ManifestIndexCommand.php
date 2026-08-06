<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;

/**
 * `php artisan splicewire:beam:manifests` — the index of indexes (beam-manifest-index). Lists every
 * registry/manifest that has described itself into the {@see ManifestIndex}, with its injection-point shape
 * and the one-liner for how a host registers into it. The map of "where are the seams" that otherwise lives
 * only in people's heads — so a new engineer (or agent) answers "what registers X, and where do I inject?"
 * from the CLI instead of a codebase sweep.
 *
 * `--seam=pipeline-chain` filters to one injection shape; `--json` emits the raw index for tooling.
 */
class ManifestIndexCommand extends Command
{
    protected $signature = 'splicewire:beam:manifests {--seam= : Filter to one injection shape (e.g. pipeline-chain, singleton-accumulator)} {--json : Emit the index as JSON}';

    protected $description = 'The index of indexes: every registry/manifest in the estate, its injection-point shape, and how to register into it.';

    public function handle(ManifestIndex $index): int
    {
        $descriptors = $index->descriptors();

        if ($seam = $this->option('seam')) {
            $filter = ManifestSeam::tryFrom($seam);
            if ($filter === null) {
                $this->components->error("Unknown seam [{$seam}]. Known: ".implode(', ', array_map(
                    static fn (ManifestSeam $s): string => $s->value,
                    ManifestSeam::cases(),
                )));

                return self::FAILURE;
            }
            $descriptors = array_values(array_filter(
                $descriptors,
                static fn (ManifestDescriptor $d): bool => $d->seam === $filter,
            ));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(static fn (ManifestDescriptor $d): array => [
                'name' => $d->name,
                'of' => $d->of,
                'seam' => $d->seam->value,
                'arity' => $d->arity->value,
                'registerHint' => $d->registerHint,
                'where' => $d->where,
                'package' => $d->package,
            ], $descriptors), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($descriptors === []) {
            $this->components->warn('No registries described into the index'.($seam ? " for seam [{$seam}]." : '.'));

            return self::SUCCESS;
        }

        // Two orthogonal axes, two columns: SEAM = how an entry gets IN; ARITY = how many entries a read
        // engages OUT. The arity column is the point — it makes "is this a registry or a manifest?" legible
        // (canon: the-seam-is-a-registry): both are one primitive, and arity is what actually differs.
        $this->table(
            ['Registry', 'Seam (in)', 'Arity (out)', 'Of', 'Where', 'Owner'],
            array_map(static fn (ManifestDescriptor $d): array => [
                $d->name,
                $d->seam->value,
                $d->arity->value,
                $d->of,
                $d->where,
                $d->package ?? '(app)',
            ], $descriptors),
        );

        $this->newLine();
        $this->legend('Injecting, by seam shape (how an entry gets in):', $descriptors,
            static fn (ManifestDescriptor $d): string => $d->seam->value,
            static fn (ManifestDescriptor $d): string => $d->seam->blurb(),
        );
        $this->newLine();
        $this->legend('Resolving, by arity (how many entries a read engages):', $descriptors,
            static fn (ManifestDescriptor $d): string => $d->arity->value,
            static fn (ManifestDescriptor $d): string => $d->arity->blurb(),
        );

        return self::SUCCESS;
    }

    /**
     * Render a de-duplicated legend for one axis: one line per distinct value present in the list.
     *
     * @param  list<ManifestDescriptor>  $descriptors
     * @param  callable(ManifestDescriptor): string  $value
     * @param  callable(ManifestDescriptor): string  $blurb
     */
    private function legend(string $heading, array $descriptors, callable $value, callable $blurb): void
    {
        $this->components->info($heading);
        $seen = [];
        foreach ($descriptors as $d) {
            $key = $value($d);
            if (! in_array($key, $seen, true)) {
                $seen[] = $key;
                $this->line("  <comment>{$key}</comment> — {$blurb($d)}");
            }
        }
    }
}
