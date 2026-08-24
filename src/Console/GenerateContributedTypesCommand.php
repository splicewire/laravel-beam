<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Codegen\AmbientTypeIndex;
use Splicewire\Beam\Codegen\ContributedTypesGenerator;

/**
 * Emits the derived contributed read types (particle-contribution-seam ticket 22) — the join between
 * an owner resource's generated TypeScript type and every slice a package contributes to its key.
 *
 * ## Where this sits in the pipeline, and why
 *
 * It runs INSIDE `splicewire:beam:generate:assets`, AFTER `typescript:transform` — it derives from that
 * command's output and verifies against it, so the order is a dependency, not a preference. Ticket 12
 * §A1 had to retract a rename that confused the umbrella (`assets`, every contract artifact) with the
 * tier npm bundle one level down (`pipelines:run resources:<tier>`); this is an ASSET — a committed
 * BE→FE contract artifact whose dirty tree after a regen is the drift signal — so it belongs to the
 * umbrella, not the bundle.
 *
 * It is its own command rather than a fold into `splicewire:beam:generate:client` because the derived
 * types describe the TYPE TREE, not the route/hook client: a host that generates no client still reads
 * particles, and its contributed keys still need declaring.
 *
 * ## Inert by default
 *
 * Nothing contributes on the overwhelming majority of hosts, and the command says so and writes an
 * empty artifact rather than skipping — an absent file and a file declaring nothing are the same fact,
 * and only one of them is legible in a diff.
 */
class GenerateContributedTypesCommand extends Command
{
    protected $signature = 'splicewire:beam:generate:contributed-types
        {--check : Verify the committed artifact matches what would be generated; write nothing}';

    protected $description = "Derive each contributed-to resource's read type (owner Data class & its contributed slices) into the ambient TypeScript tree";

    public function handle(ContributedTypesGenerator $generator): int
    {
        $directory = (string) config('beam.client.types.dir');
        $source = $directory.'/'.config('beam.client.types.source');
        $target = $directory.'/'.config('beam.client.types.out');

        if (! is_file($source)) {
            $this->components->error(
                "No emitted type tree at [{$source}] — run `typescript:transform` first, or point ".
                '`beam.client.types.dir`/`.source` at where this host writes it.'
            );

            return self::FAILURE;
        }

        $derived = $generator->derive();
        $referenced = $generator->referencedTypes($derived);

        // ⚠️ The check the map's three host-rooted-discovery failures earn: a slice lives in a PACKAGE,
        // and a host whose `#[TypeScript]` scan only reaches `app_path()` emits no type for it. Deriving
        // an intersection over a type that was never written produces a dangling reference in a `.d.ts`
        // nobody compiles until much later. Fail here, naming the class.
        $missing = AmbientTypeIndex::fromSource((string) file_get_contents($source))->missing($referenced);

        if ($missing !== []) {
            $this->components->error(
                'These classes are referenced by a contributed read type but are not declared in ['
                .basename($source)."]:\n  - ".implode("\n  - ", $missing)."\n"
                .'Each one is a Data class in a package. Widen this host\'s TypeScript scan to cover the '
                .'package `src/Data` roots (a host-rooted default scan cannot see them), or drop the '
                .'`#[TypeScript]` gap on the class itself.'
            );

            return self::FAILURE;
        }

        $rendered = $generator->render($derived);

        if ($this->option('check')) {
            $current = is_file($target) ? (string) file_get_contents($target) : null;

            if ($current === $rendered) {
                $this->components->info('Contributed read types are up to date.');

                return self::SUCCESS;
            }

            $this->components->error(
                "[{$target}] is stale — run `splicewire:beam:generate:contributed-types` and commit the result."
            );

            return self::FAILURE;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($target, $rendered);

        $slices = array_sum(array_map(fn (array $entry): int => count($entry['slices']), $derived));

        $this->components->info(
            $derived === []
                ? "No contributions registered — wrote an empty [{$target}]."
                : count($derived).' resource(s), '.$slices." contributed key(s) → [{$target}]."
        );

        foreach ($derived as $key => $entry) {
            $this->components->twoColumnDetail($key, implode(', ', array_keys($entry['slices'])));
        }

        return self::SUCCESS;
    }
}
