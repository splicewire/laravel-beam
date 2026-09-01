<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Codegen\AmbientTypeIndex;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

/**
 * Verifies that every DTO the particle estate DECLARES for export actually emitted into the ambient
 * TypeScript tree — the whole-surface widening of the check
 * {@see GenerateContributedTypesCommand} already applies to contribution slices.
 *
 * ## Where this sits, and why
 *
 * INSIDE `splicewire:beam:generate:assets`, AFTER `typescript:transform`, for the identical reason its
 * sibling runs there: it verifies against that command's output, so the order is a dependency and not a
 * preference. It writes NOTHING. That is deliberate and is the boundary this command must not cross —
 * making a declared class emit would change what the frontend is typed against, and silently retyping a
 * frontend is a worse failure than the one being fixed. This adds a CHECK.
 *
 * ## What is a failure and what is merely counted
 *
 * {@see DeclaredParticleTypes::partition()} draws the line, and it is the estate's house distinction
 * between "nothing missing" and "could not look":
 *
 *  - a class carrying `#[TypeScript]` and absent from the tree is a FAILURE, named, with the declaration
 *    slots that reference it — it asked to emit and did not, which on this estate almost always means a
 *    host-rooted `#[TypeScript]` scan that cannot reach a package's `src/Data` root;
 *  - a class carrying no `#[TypeScript]` is COUNTED. Whether this host emits it depends on collectors
 *    beam cannot see, and some declared DTOs are legitimately not public frontend API. Counting is the
 *    honest report; failing would be a guess.
 *  - a declaration naming a class that does not exist is a FAILURE of its own kind, reported separately
 *    because it is not a TypeScript problem at all.
 *
 * ## Coverage can vary by host config, and the command says so
 *
 * Attribute-declared particles are registered unconditionally in `BeamServiceProvider::boot()`, so they
 * are present for any console command. Operations registered IMPERATIVELY through `Particle::ops()`
 * ({@see ParticleMounter::ops()}) are registered as a side effect of a
 * ROUTE FILE loading — and Laravel skips loading route files entirely when routes are cached. So under
 * `route:cache` this command sees a strictly smaller operation set, and reporting a clean pass would be
 * reporting a fact it did not establish. It warns instead, and `--json` carries `coverage`.
 *
 * ## Inert when there is nothing to say
 *
 * A host whose declared surface is fully emitted gets one line. It is a check, not a narrator.
 */
class VerifyDeclaredTypesCommand extends Command
{
    protected $signature = 'splicewire:beam:verify:declared-types
        {--json : Emit a machine-readable summary instead of the human report}';

    protected $description = "Verify every declared particle DTO that asked to emit (#[TypeScript]) is present in this host's ambient type tree";

    public function handle(DeclaredParticleTypes $declared): int
    {
        $source = config('beam.client.types.dir').'/'.config('beam.client.types.source');

        if (! is_file($source)) {
            $this->components->error(
                "No emitted type tree at [{$source}] — run `typescript:transform` first, or point ".
                '`beam.client.types.dir`/`.source` at where this host writes it.'
            );

            return self::FAILURE;
        }

        $slots = $declared->declared();
        $partition = $declared->partition(array_keys($slots));

        $index = AmbientTypeIndex::fromSource((string) file_get_contents($source));

        $missing = [];

        foreach ($partition['exported'] as $class => $type) {
            if (! $index->has($type)) {
                $missing[$class] = $type;
            }
        }

        // ⚠️ Routes cached ⇒ `Particle::ops()` never ran ⇒ imperatively-registered operations are absent
        // from the registry this pass reads. "Could not look" is not "nothing missing".
        $partial = $this->laravel->routesAreCached();

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'declared' => count($slots),
                'exported' => count($partition['exported']),
                'unexported' => count($partition['unexported']),
                'absent' => array_values($partition['absent']),
                'missing' => $missing,
                'coverage' => $partial ? 'partial' : 'full',
            ]));

            return $missing === [] && $partition['absent'] === [] ? self::SUCCESS : self::FAILURE;
        }

        // Why this warns rather than fails, and why it names a fix rather than just a workaround:
        // `Particle::ops()` both discovers and mounts, so a package whose ops reach the registry only
        // through a route file declares nothing in a console context. beam-calendars hit exactly this
        // and solved it — `Resources.php` calls `AttributedParticleDiscovery->discover()` on its own
        // `src/Data` and `src/Ops` at register time, with the reasoning written down: it "makes the
        // declaration true of the PROCESS rather than of the REQUEST." That is the durable fix;
        // `route:clear` only makes this one pass honest.
        if ($partial) {
            $this->components->warn(
                'Routes are CACHED, so route files did not load and any operation registered only '
                .'through `Particle::ops()` is missing from the registry this pass read — coverage is '
                .'PARTIAL. To make a package immune rather than to work around it, have it call '
                .'`AttributedParticleDiscovery->discover()` on its own declaration roots at register '
                .'time (see beam-calendars `Resources.php`), which makes its declarations true of the '
                .'process rather than of the request. `route:clear` makes this pass honest meanwhile.'
            );
        }

        // One finding per line rather than one paragraph: the console renderer folds embedded newlines out
        // of an `error()` body, and a list of eight class names run together is the kind of output a reader
        // skips — which would waste the whole point of naming them.
        if ($partition['absent'] !== []) {
            $this->components->error(
                count($partition['absent']).' particle declaration(s) name a class that does not exist.'
            );

            $this->components->bulletList(array_map(
                fn (string $class): string => $class.'  ← '.implode('; ', $slots[$class]),
                $partition['absent'],
            ));
        }

        if ($missing !== []) {
            $this->components->error(
                count($missing).' declared DTO(s) carry `#[TypeScript]` but are absent from ['
                .basename($source).'] — each asked to be exported and was not written.'
            );

            $this->components->bulletList(array_map(
                fn (string $class, string $type): string => $type.'  ← '.implode('; ', $slots[$class]),
                array_keys($missing),
                $missing,
            ));

            // Order matters, and it is not the order you would guess. The first run of this check
            // found 8 classes and the diagnosis went wrong TWICE before landing — first "the scan is
            // not recursive" (it is: spatie discovers through `Discover::in()`), then "the host
            // cannot reach the package" (it could: the directory was in the live scan list). The
            // actual cause was the dullest one: the classes had been declared THREE HOURS earlier and
            // nobody had re-run the transform. So staleness leads here, because it is both the most
            // common cause and the cheapest to rule out.
            $this->components->warn(
                'Check in this order. (1) STALE ARTIFACT — most likely: the declaration is newer than '
                .'the emitted tree. Re-run `typescript:transform` and re-check before investigating '
                .'anything else. (2) REACH: the declaring package is not in this host\'s scan list at '
                .'all — dump the configured directories and look, rather than assuming. (3) FILTER: '
                .'the host\'s own transformer declined the class.'
            );
        }

        if ($missing === [] && $partition['absent'] === []) {
            $this->components->info(
                count($partition['exported']).' of '.count($slots)
                .' declared particle DTO(s) asked to export, and all of them did.'
            );
        }

        if ($partition['unexported'] !== []) {
            // Counted, never failed: no `#[TypeScript]` means the class asked for nothing, and a host's
            // collectors may emit it anyway. This line is the "could not look" half of the report.
            $this->components->twoColumnDetail(
                'not declared for export (no #[TypeScript]) — not checked',
                (string) count($partition['unexported'])
            );
        }

        return $missing === [] && $partition['absent'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
