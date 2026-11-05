<?php

namespace Splicewire\Beam\Console;

use Closure;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shared machinery for the five generators: `splicewire:beam:make:particle-resource`,
 * `splicewire:beam:make:particle-op`, `splicewire:beam:make:particle-relative`,
 * `splicewire:beam:make:particle-backing` and `splicewire:beam:make:workflow`.
 *
 * ## Why a generator is a convergence mechanism and not a convenience
 * The estate had no `make:*` command of any kind, and that absence — not carelessness — is the mechanical
 * reason particle deviation propagates. An agent asked to add a surface reverse-engineers the pattern from
 * whatever example it happened to open, inherits that example's deviations along with its shape, and the copy
 * becomes the next agent's example. A generator replaces the sampled example with a fixed one, so the pattern
 * stops being transmitted by copy and starts being transmitted by template.
 *
 * ## The emitted declaration precedes the emitted logic, deliberately
 * Every stub these commands mint carries its shape slots ALREADY FILLED — a resource its `data:`/`input:`
 * pair, an operation its `input:`/`output:`/`ability:` triple — pointing at companion Data classes the
 * generator also writes. The alternative (emit the slots commented out, or omit them) reproduces exactly the
 * failure {@see UndeclaredSurfaceAudit} exists to detect: a surface that works perfectly and declares nothing.
 * Filling in a property is a local edit; noticing an absent slot is not, which is why the slot is always
 * present and the property is the placeholder.
 *
 * ## Multi-file output
 * {@see GeneratorCommand::handle()} writes exactly one file, so these commands drive their own primary write
 * and then emit companions through {@see writeCompanion()}. A companion that already exists is REPORTED and
 * SKIPPED rather than overwritten — re-running the generator to add an op to an existing namespace must never
 * silently discard a Data class someone has since filled in.
 *
 * ## Stubs
 * Stubs ship in the package's `stubs/` directory and are overridable per host at the same relative path under
 * the app's base path — the framework's own `resolveStubPath` convention. They are `.stub`, not `.php`, for
 * the same reason beam's publish-only migrations are: the extension keeps every scanner, autoloader and
 * audit in the estate from ever treating a template as live code.
 *
 * ## Verify after generating
 * The chassis promises that a generated surface, mounted, has zero conformance findings. Until make-surface 09
 * only the tests checked that, so a host's overridden stub could emit a surface that declares nothing and the
 * generator still exited 0. Now every run ends in {@see verified()}, which checks the files THIS run wrote or
 * kept (never the rest of the host) and exits 1 on a finding; `--no-verify` skips it:
 *
 * - every emitted file lints (`php -l`, in a child process, so a broken stub cannot fatal the generator);
 * - no emitted file pins the central connection ({@see CentralPinJustificationAudit::pinsInSource()});
 * - the generator's own declaration check, {@see declarationFindings()}: the resource and op generators load
 *   what they wrote, register it through real attribute discovery, mount it under {@see VERIFY_PREFIX} in this
 *   process only, and run {@see UndeclaredSurfaceAudit} over that prefix — the same check their tests assert.
 *   The backing generator reflects the capability interfaces it emitted. The relative and workflow generators'
 *   tests assert the emitted text and that it is real PHP, so the lint is their check.
 *
 * Nothing the check mounts or registers outlives the generator's process; the host still routes the surface
 * itself, from the printed mount line.
 */
abstract class ParticleGeneratorCommand extends GeneratorCommand
{
    /** The throwaway route prefix a declaration check mounts under, in the generator's own process only. */
    public const VERIFY_PREFIX = 'beam-make-verify';

    /**
     * Every class this run wrote or kept, primary first: qualified class => absolute path.
     *
     * @var array<string, string>
     */
    protected array $emitted = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct($files);

        // Added here rather than in each generator's getOptions(), so a sixth generator cannot ship without it.
        $this->addOption('no-verify', null, InputOption::VALUE_NONE, 'Skip the post-write conformance check (lint, central pin, declared shape)');
    }

    /**
     * Token map applied to the stub AFTER the framework's own namespace/class substitution and BEFORE import
     * sorting (a token may itself BE an import, so sorting first would sort placeholders).
     *
     * @return array<string, string>
     */
    abstract protected function tokens(): array;

    protected function buildClass($name): string
    {
        // GeneratorCommand calls this only when it is about to write the primary file, so it is where the
        // primary joins the run's emitted set (a refusal on an existing file never reaches here).
        $this->emitted = [$name => $this->getPath($name)] + $this->emitted;

        return $this->renderStub($this->getStub(), $name, $this->tokens());
    }

    /**
     * Render one stub into finished source for a fully-qualified class name.
     *
     * @param  array<string, string>  $tokens
     */
    protected function renderStub(string $stubPath, string $qualifiedClass, array $tokens): string
    {
        $contents = $this->files->get($stubPath);

        $this->replaceNamespace($contents, $qualifiedClass);
        $contents = $this->replaceClass($contents, $qualifiedClass);

        foreach ($tokens as $token => $value) {
            // A prose token lands inside a docblock, so it is wrapped to the estate's comment width and
            // re-prefixed — an unwrapped 200-column `*` line in a file the author did not write reads as
            // machine output, and machine-looking output is the first thing a reader stops trusting.
            if ($token === 'purpose') {
                $value = wordwrap($value, 108, "\n * ");
            }

            $contents = str_replace('{{ '.$token.' }}', $value, $contents);
        }

        return $this->sortImports($contents);
    }

    /**
     * Write one of the run's secondary files (a companion Data class).
     *
     * @param  array<string, string>  $tokens
     */
    protected function writeCompanion(string $stub, string $qualifiedClass, array $tokens): void
    {
        $path = $this->getPath($qualifiedClass);

        // A kept companion is still part of the surface the primary declares, so it is verified with it.
        $this->emitted[$qualifiedClass] = $path;

        if ($this->files->exists($path)) {
            $this->components->twoColumnDetail($qualifiedClass, '<fg=yellow>exists, kept</>');

            return;
        }

        $this->makeDirectory($path);
        $this->files->put($path, $this->renderStub($this->resolveStubPath($stub), $qualifiedClass, $tokens));

        $this->components->twoColumnDetail($qualifiedClass, '<fg=green>created</>');
    }

    /**
     * The package stub, unless the host has published its own override at the same relative path — the
     * framework's `resolveStubPath` convention, so customizing beam's stubs works the way customizing
     * Laravel's does.
     */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : dirname(__DIR__, 2).'/'.trim($stub, '/');
    }

    /**
     * The Data-class namespace the companions land in. Separate from the primary class's namespace because an
     * operation is not a Data class and does not belong beside one.
     */
    protected function dataNamespace(): string
    {
        $override = $this->hasOption('data-namespace') ? $this->option('data-namespace') : null;

        return is_string($override) && $override !== ''
            ? trim($override, '\\')
            : $this->rootNamespace().'Data';
    }

    /**
     * The framework's {@see GeneratorCommand::qualifyModel()} prefixes `App\Models\` onto anything that does not
     * already start with the root namespace — the right default for an app whose models all live under `App\`,
     * and the wrong one here, where the ordinary model is package-tier (`Splicewire\Tower\Models\…`). Measured at
     * the flagship (tenant-sync 16): `--model='Splicewire\Tower\Models\TenantSync'` emitted
     * `use App\Models\Splicewire\Tower\Models\TenantSync;`. A name carrying a namespace separator is already
     * qualified and is kept as given (leading `\` stripped, `/` normalised); only a bare class name takes the
     * framework's default.
     */
    protected function qualifyModel(string $model): string
    {
        $model = str_replace('/', '\\', ltrim($model, '\\/'));

        return str_contains($model, '\\') ? $model : parent::qualifyModel($model);
    }

    /**
     * The particle resource key from a class base name: kebab-cased and plural, the estate's key shape
     * (`library-lyrics`, `timeline-projects`) rather than the studly class name.
     */
    protected function keyFrom(string $base): string
    {
        return Str::kebab(Str::plural($base));
    }

    /**
     * Guard the one name collision that produces UNCOMPILABLE output rather than merely odd output: the
     * generated class and its model sharing a short name, which makes the emitted `use <Model>;` a redeclaration.
     * Cheap to check, and the failure it prevents is a parse error in a file the author did not write.
     */
    protected function modelNameCollides(string $qualifiedClass, string $model): bool
    {
        if (class_basename($qualifiedClass) !== class_basename($model)) {
            return false;
        }

        $this->components->error(sprintf(
            'The generated class and its model would both be named [%s], so the emitted `use %s;` cannot '
            .'compile. Rename one, or pass an explicit --model.',
            class_basename($model),
            $model,
        ));

        return true;
    }

    /** The closing summary: the whole emitted surface, plus the one line of wiring the generator cannot do. */
    protected function report(string $mountHint): void
    {
        $this->newLine();
        $this->components->info('Mount it — the attribute declares, the host routes:');
        $this->line('    '.$mountHint);
    }

    /**
     * The run's exit code once its files are written: SUCCESS, or FAILURE when the emitted set has a finding.
     * Every generator's successful path returns this rather than SUCCESS (see the class docblock).
     */
    protected function verified(): int
    {
        if ($this->option('no-verify')) {
            return self::SUCCESS;
        }

        $findings = [];

        foreach ($this->emitted as $class => $path) {
            $lint = new Process([PHP_BINARY, '-l', $path], env: ['XDEBUG_MODE' => 'off']);
            $lint->run();

            if (! $lint->isSuccessful()) {
                $findings[] = sprintf('%s does not lint: %s', $class, trim($lint->getOutput().$lint->getErrorOutput()));
            }
        }

        // A file that does not lint is not loaded or audited further: requiring it would fatal this process.
        if ($findings === []) {
            $pins = new CentralPinJustificationAudit([]);

            foreach ($this->emitted as $class => $path) {
                foreach ($pins->pinsInSource((string) $this->files->get($path), $path) as $pin) {
                    $findings[] = sprintf('%s pins the central connection (%s, line %d)', $class, $pin['form'], $pin['line']);
                }
            }

            try {
                array_push($findings, ...$this->declarationFindings());
            } catch (Throwable $e) {
                $findings[] = sprintf('the declaration check could not run: %s', $e->getMessage());
            }
        }

        if ($findings === []) {
            $this->components->twoColumnDetail('Verified', '<fg=green>no findings</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error(sprintf(
            'The generated surface has %d finding(s); fix the stub or the emitted files (or rerun with --no-verify):',
            count($findings),
        ));
        $this->components->bulletList($findings);

        return self::FAILURE;
    }

    /**
     * The generator's own declaration check, run after the lint and central-pin checks pass. None by default.
     *
     * @return list<string>
     */
    protected function declarationFindings(): array
    {
        return [];
    }

    /**
     * Load the emitted classes into this process, so a declaration check can discover and reflect them. A host's
     * autoloader usually finds them already; a fresh base path (the tests' throwaway host) needs the require.
     */
    protected function loadEmitted(): void
    {
        foreach ($this->emitted as $class => $path) {
            if (! class_exists($class, false) && ! interface_exists($class, false) && ! trait_exists($class, false)) {
                require_once $path;
            }
        }
    }

    /**
     * Mount a surface under {@see VERIFY_PREFIX} and return the undeclared-surface rows under that prefix.
     *
     * @param  Closure(): mixed  $mount  the same call the printed mount line names
     * @return list<string>
     */
    protected function undeclaredWhenMounted(Closure $mount): array
    {
        Route::prefix(self::VERIFY_PREFIX)->group($mount);

        $audit = new UndeclaredSurfaceAudit(
            $this->laravel->make(ParticleResourceRegistry::class),
            $this->laravel->make(ParticleOperationRegistry::class),
        );

        $rows = array_filter(
            $audit->undeclared(),
            fn (array $row) => str_starts_with($row['uri'], self::VERIFY_PREFIX.'/'),
        );

        return array_values(array_map(fn (array $row) => sprintf(
            '%s %s has an undeclared shape (%s)',
            implode('|', $row['methods']),
            $row['uri'],
            UndeclaredSurfaceAudit::CHECK,
        ), $rows));
    }
}
