<?php

namespace Splicewire\Beam\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\Generator\ConfigFileOutline;
use Splicewire\Beam\Config\Generator\ConfigLiteralComparisons;
use Splicewire\Beam\Config\Generator\ConfigSectionDrift;
use Splicewire\Beam\Config\Generator\ConfigSectionEmitter;
use Splicewire\Beam\Config\Generator\ConfigSectionInferrer;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Adopting the Config Sections convention is one command (config-sections ticket 06):
 *
 *     php artisan splicewire:beam:make:config-section splicewire/laravel-beam-ux beam.ux
 *
 * It infers a section for `<root>` from `config(<root>)` (the merged array) and the root's config file
 * ({@see ConfigSectionInferrer}), and writes one class per record or map entry under the package's `src/Config/` (a
 * host's `app/Config/`). Every key comes out deploy-only and not overridable; the titles and descriptions are drafts.
 *
 * Like the other beam generators it checks what it wrote and exits 1 on a finding (`--no-verify` skips the check):
 * every file lints, the section's schema generates, the package defaults validate against it, and every merged key is
 * covered ({@see ConfigSectionCoverage}).
 *
 * When the section already exists it writes NOTHING: it reports how the config has drifted from the section
 * ({@see ConfigSectionDrift}) and exits 1 when it has, so hand-edited attributes are never overwritten.
 */
class MakeConfigSectionCommand extends Command
{
    protected $signature = 'splicewire:beam:make:config-section
        {package : The composer name of the package that owns the root, or `host`}
        {root : The config root, as config() reads it (`beam.ux`, `app.chat`)}
        {--config= : The config file (default: found from the root under the owner\'s config directory)}
        {--path= : The directory to write into (default: the owner\'s src/Config, or app/Config for the host)}
        {--namespace= : The classes\' namespace (default: from the owner\'s PSR-4 autoload)}
        {--class= : The section class name (default: the studly root + Section)}
        {--scan=* : Source directories scanned for literal comparisons (default: the owner\'s source directory)}
        {--no-verify : Skip the post-write check (lint, schema, defaults, coverage)}';

    protected $description = 'Infer a Config Section for a config root from its merged array and config file (ADR-0245); report drift when one exists.';

    public function handle(Filesystem $files): int
    {
        $package = (string) $this->argument('package');
        $root = (string) $this->argument('root');
        $merged = config($root);

        if (! is_array($merged)) {
            $this->components->error("No config is loaded at [{$root}]; register the package's config (mergeConfigFrom) first.");

            return self::FAILURE;
        }

        $owner = $this->owner($package);

        if ($owner === null) {
            $this->components->error("[{$package}] is not an installed package (and not `host`).");

            return self::FAILURE;
        }

        [$file, $within] = $this->option('config') ? [(string) $this->option('config'), []] : $this->configFile($owner['config'], $root);

        if ($file === null || ! is_file($file)) {
            $this->components->error("No config file for [{$root}] under {$owner['config']}; pass --config.");

            return self::FAILURE;
        }

        $namespace = trim((string) ($this->option('namespace') ?: $owner['namespace']), '\\');
        $path = rtrim((string) ($this->option('path') ?: $owner['path']), '/');
        $scan = array_values(array_map('strval', array_filter((array) $this->option('scan')))) ?: [$owner['source']];

        $inferred = (new ConfigSectionInferrer(
            ConfigFileOutline::of($file, $within),
            ConfigLiteralComparisons::in($root, $scan),
            fn (string $class): ?string => $this->registryFor($class),
        ))->infer($root, $merged, $this->option('class') ?: null);

        $sectionFile = $path.'/'.$inferred->sectionClass.'.php';

        if ($files->exists($sectionFile)) {
            return $this->drift((new ConfigSectionDrift)->between($inferred, $sectionFile), $sectionFile);
        }

        $sources = (new ConfigSectionEmitter)->emit($inferred, $namespace, $this->display($file));

        foreach (array_keys($sources) as $class) {
            if ($files->exists($path.'/'.$class.'.php')) {
                $this->components->error("{$path}/{$class}.php already exists and is not this root's section; nothing was written.");

                return self::FAILURE;
            }
        }

        $files->ensureDirectoryExists($path);
        $written = [];

        foreach ($sources as $class => $source) {
            $files->put($written[$namespace.'\\'.$class] = $path.'/'.$class.'.php', $source);
            $this->components->twoColumnDetail($namespace.'\\'.$class, $this->display($path.'/'.$class.'.php'));
        }

        $this->line(sprintf('  Register it: <fg=gray>app(ConfigSectionRegistry::class)->section(%s::class, owner: \'%s\'%s);</>', $inferred->sectionClass, $package, $package === 'host' ? '' : ', packageFile: <package>/'.$this->display($file)));

        if ($this->option('no-verify')) {
            return self::SUCCESS;
        }

        return $this->verified($written, $namespace.'\\'.$inferred->sectionClass, $merged, $file, $within);
    }

    /**
     * @param  array<string, string>  $written  class => path
     * @param  array<array-key, mixed>  $merged
     * @param  list<string>  $within
     */
    protected function verified(array $written, string $section, array $merged, string $file, array $within): int
    {
        $findings = [];

        foreach ($written as $class => $path) {
            $lint = new Process([PHP_BINARY, '-l', $path], env: ['XDEBUG_MODE' => 'off']);
            $lint->run();

            if (! $lint->isSuccessful()) {
                $findings[] = sprintf('%s does not lint: %s', $class, trim($lint->getOutput().$lint->getErrorOutput()));
            }
        }

        if ($findings === []) {
            try {
                foreach ($written as $class => $path) {
                    if (! class_exists($class, false)) {
                        require_once $path;
                    }
                }

                /** @var class-string<ConfigSection> $section */
                $schema = app(ConfigSectionSchema::class)->for($section);
                $defaults = $this->defaults($file, $within);

                if ($defaults !== null && ! app(AcceptanceGate::class)->accepts($defaults, $schema)) {
                    $findings[] = sprintf('the defaults in %s do not validate against the emitted section', $this->display($file));
                }

                foreach (ConfigSectionCoverage::uncovered($section, $merged) as $pointer) {
                    $findings[] = sprintf('%s is in config() but no emitted class covers it', $pointer);
                }
            } catch (Throwable $e) {
                $findings[] = sprintf('the emitted section could not be checked: %s', $e->getMessage());
            }
        }

        foreach ($written as $class => $path) {
            $this->components->twoColumnDetail($class, sprintf('<fg=gray>sha256 %s</>', substr(hash_file('sha256', $path) ?: '?', 0, 16)));
        }

        if ($findings === []) {
            $this->components->twoColumnDetail('Verified', '<fg=green>no findings</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error(sprintf('The generated section has %d finding(s); fix the config or the emitted files (or rerun with --no-verify):', count($findings)));
        $this->components->bulletList($findings);

        return self::FAILURE;
    }

    /** @param list<string> $drift */
    protected function drift(array $drift, string $sectionFile): int
    {
        if ($drift === []) {
            $this->components->info("{$this->display($sectionFile)} exists and matches the config; nothing was written.");

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%s exists; nothing was written. The config has drifted from it in %d place(s):', $this->display($sectionFile), count($drift)));
        $this->components->bulletList($drift);

        return self::FAILURE;
    }

    /**
     * The file's own values under `$within`, evaluated in isolation (the package tier).
     *
     * @param  list<string>  $within
     * @return array<array-key, mixed>|null
     */
    protected function defaults(string $file, array $within): ?array
    {
        $values = (static fn (string $path): mixed => require $path)($file);

        foreach ($within as $segment) {
            $values = is_array($values) ? ($values[$segment] ?? null) : null;
        }

        return is_array($values) ? $values : null;
    }

    /**
     * Where an owner keeps its config, its source, and the Config namespace.
     *
     * @return array{config: string, source: string, path: string, namespace: string}|null
     */
    protected function owner(string $package): ?array
    {
        if ($package === 'host') {
            return ['config' => config_path(), 'source' => app_path(), 'path' => app_path('Config'), 'namespace' => app()->getNamespace().'Config'];
        }

        $base = InstalledVersions::isInstalled($package) ? InstalledVersions::getInstallPath($package) : null;

        if ($base === null || ! is_dir($base)) {
            return null;
        }

        $base = (string) realpath($base);
        $composer = json_decode((string) @file_get_contents($base.'/composer.json'), true);
        $psr4 = is_array($composer) ? ($composer['autoload']['psr-4'] ?? []) : [];
        $prefix = (string) array_key_first($psr4);
        $source = $base.'/'.trim((string) (is_array($psr4[$prefix] ?? null) ? $psr4[$prefix][0] : ($psr4[$prefix] ?? 'src')), '/');

        return ['config' => $base.'/config', 'source' => $source, 'path' => $source.'/Config', 'namespace' => $prefix.'Config'];
    }

    /**
     * The deepest existing file for a root: `config/a/b/c.php`, else `config/a/b.php['c']`, else `config/a.php['b']['c']`.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    protected function configFile(string $directory, string $root): array
    {
        $segments = explode('.', $root);

        for ($i = count($segments); $i >= 1; $i--) {
            $file = $directory.'/'.implode('/', array_slice($segments, 0, $i)).'.php';

            if (is_file($file)) {
                return [$file, array_slice($segments, $i)];
            }
        }

        return [null, []];
    }

    /** The root of a registry whose declared entry type `$class` satisfies, for a combobox over its keys. */
    protected function registryFor(string $class): ?string
    {
        if (! class_exists($class) || ! app()->bound(RegistryIndex::class)) {
            return null;
        }

        try {
            $index = app(RegistryIndex::class);

            foreach ($index->keys() as $key) {
                $type = $index->declarationAt($key)?->entryType;

                if (is_string($type) && $type !== 'mixed' && (class_exists($type) || interface_exists($type)) && is_a($class, $type, true)) {
                    return (string) $key;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    protected function display(string $path): string
    {
        return Str::after($path, base_path().'/');
    }
}
