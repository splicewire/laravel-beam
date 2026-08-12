<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;

/**
 * Shared machinery for the two particle scaffolders (`splicewire:beam:make:particle-resource` and
 * `splicewire:beam:make:particle-op`).
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
 */
abstract class ParticleGeneratorCommand extends GeneratorCommand
{
    /**
     * Token map applied to the stub AFTER the framework's own namespace/class substitution and BEFORE import
     * sorting (a token may itself BE an import, so sorting first would sort placeholders).
     *
     * @return array<string, string>
     */
    abstract protected function tokens(): array;

    protected function buildClass($name): string
    {
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
}
