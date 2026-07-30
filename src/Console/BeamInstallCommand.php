<?php

declare(strict_types=1);

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * `beam:install` — the ONE command that stands up the whole beam stack.
 *
 * Phase 1 (beam-write-pipeline ticket 08) made it manifest-driven: it iterates the
 * {@see BeamInstallManifest} core-first, runs each registered package's `vendor:publish` tags, then
 * migrates once — a beam-* package joins by registering its own {@see InstallStep} from its own provider,
 * so this command NEVER names a consumer.
 *
 * Phase 2 (beam-particle-rename ticket 10) wraps that with an interactive front door: a laravel/prompts
 * wizard that asks the config questions the Phase-1 seams now expose (the `beam_` table prefix, tenancy
 * mode, the schema-source order, which optional modules to install), applies them, then chains the same
 * manifest steps. A retrofit host completes setup from this one command — e.g. answer prefix `''` +
 * schema-sources `file` and beam provisions no `beam_schemas` table, dropping cleanly into a host that
 * already owns its tables. Every answer is also exposed as an option, so the wizard is fully scripted for
 * non-interactive runs (CI, tests) — no prompt fires when `--no-interaction` is set.
 */
final class BeamInstallCommand extends Command
{
    protected $signature = 'beam:install
        {--force : Overwrite any already-published files}
        {--prefix= : Beam table prefix (config beam.core.table_prefix); pass an empty string for no prefix}
        {--schema-sources= : Comma list of schema sources in read/write order, e.g. "db,file" or "file"}
        {--tenancy= : "single" (one database) or "multi" (tenant-scoped)}
        {--modules= : Comma list of optional beam modules to install (name substrings); empty ⇒ core only}';

    protected $description = 'Interactively configure + install the whole beam stack (core-first) from the self-registration manifest.';

    public function handle(BeamInstallManifest $manifest): int
    {
        $steps = $manifest->steps();

        if ($steps === []) {
            $this->warn('beam:install — nothing registered in the manifest.');

            return self::SUCCESS;
        }

        $interactive = $this->input->isInteractive();

        if ($interactive) {
            intro('beam:install');
            note($this->banner());
        }

        // 1. Gather the answers the Phase-1 seams expose. Each is option-first (scriptable), then a prompt
        //    when interactive, then null ⇒ leave config as-is — so a non-interactive run with no options is
        //    a pure Phase-1 install against today's config (the existing behaviour, unchanged).
        $prefix = $this->answer('prefix', $interactive, fn () => text(
            label: 'Beam table prefix',
            default: (string) config('beam.core.table_prefix', 'beam_'),
            hint: 'Empty = no prefix (retrofit into a host that owns its own tables).',
        ));

        $tenancy = $this->answer('tenancy', $interactive, fn () => select(
            label: 'Tenancy mode',
            options: ['single' => 'Single database', 'multi' => 'Multi-tenant (tenant-scoped)'],
            default: 'single',
        ));

        $sources = $this->answer('schema-sources', $interactive, fn () => select(
            label: 'Schema source order (first is written to)',
            options: [
                'db,file' => 'Database, then filesystem fallback',
                'file' => 'Filesystem only (provisions no beam_schemas table)',
                'db' => 'Database only',
            ],
            default: 'db,file',
        ));

        // 2. Apply the answers to runtime config BEFORE any publish/migrate, so this same run's migrations
        //    (which resolve every table through Beam::table()) land under the chosen prefix, and a
        //    file-only schema store provisions no beam_schemas.
        if ($prefix !== null) {
            config(['beam.core.table_prefix' => $prefix]);
        }
        if ($sources !== null) {
            config(['beam.core.schema.sources' => $this->parseList($sources)]);
        }
        if ($tenancy !== null) {
            config(['beam.core.tenancy' => $tenancy]);
        }

        // 3. Which optional modules? Core (order 0) is always installed; the rest are selectable.
        [$core, $optional] = $this->partitionSteps($steps);
        $selected = $this->selectModules($optional, $interactive);
        $runSteps = array_merge($core, $selected);

        // 4. Run the chained install (Phase-1 mechanics), then persist the answered config so the choices
        //    survive the next boot.
        $this->runSteps($runSteps, $this->stepsMigrate($runSteps));
        $this->persistConfig($prefix, $sources, $tenancy);

        if ($interactive) {
            outro('beam stack installed.');
        } else {
            $this->info('beam stack installed.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve one answer: the option value if given (scriptable), else the prompt when interactive, else
     * null (⇒ leave config as-is). An option is honoured even when empty (`--prefix=` means "no prefix").
     */
    private function answer(string $option, bool $interactive, callable $prompt): ?string
    {
        $value = $this->option($option);

        if ($value !== null) {
            return $value;
        }

        return $interactive ? (string) $prompt() : null;
    }

    /**
     * Split the manifest into the always-run core steps (order 0) and the operator-selectable rest.
     *
     * @param  list<InstallStep>  $steps
     * @return array{0: list<InstallStep>, 1: list<InstallStep>}
     */
    private function partitionSteps(array $steps): array
    {
        $core = array_values(array_filter($steps, static fn (InstallStep $s): bool => $s->order === 0));
        $optional = array_values(array_filter($steps, static fn (InstallStep $s): bool => $s->order !== 0));

        return [$core, $optional];
    }

    /**
     * Which optional modules to install. `--modules` (even empty) is authoritative; else a multiselect
     * when interactive; else all of them (the unchanged non-interactive full-stack install).
     *
     * @param  list<InstallStep>  $optional
     * @return list<InstallStep>
     */
    private function selectModules(array $optional, bool $interactive): array
    {
        if ($optional === []) {
            return [];
        }

        $option = $this->option('modules');

        if ($option !== null) {
            $wanted = $this->parseList($option);

            return array_values(array_filter(
                $optional,
                fn (InstallStep $s): bool => $this->matchesAny($s->package, $wanted),
            ));
        }

        if (! $interactive) {
            return $optional;
        }

        $chosen = multiselect(
            label: 'Which optional beam modules?',
            options: array_map(static fn (InstallStep $s): string => $s->package, $optional),
            default: array_map(static fn (InstallStep $s): string => $s->package, $optional),
            hint: 'Space to toggle. Core is always installed.',
        );

        return array_values(array_filter($optional, static fn (InstallStep $s): bool => in_array($s->package, $chosen, true)));
    }

    /** @param  list<string>  $needles */
    private function matchesAny(string $package, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($package, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Phase-1 publish/migrate mechanics, over the chosen steps.
     *
     * @param  list<InstallStep>  $steps
     */
    private function runSteps(array $steps, bool $migrates): void
    {
        $force = (bool) $this->option('force');

        foreach ($steps as $step) {
            $this->line("beam:install → {$step->package}");

            foreach ($step->publishTags as $tag) {
                $this->callSilent('vendor:publish', array_merge(
                    ['--tag' => $tag],
                    $force ? ['--force' => true] : [],
                ));
            }
        }

        if ($migrates) {
            $this->call('migrate', $force ? ['--force' => true] : []);
        }
    }

    /** @param  list<InstallStep>  $steps */
    private function stepsMigrate(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->migrates) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist the answered values into the published `config/beam/core.php` so they survive the next boot.
     * Best-effort + non-fatal: if the host hasn't published the config (or it isn't writable), the runtime
     * config still governs this run — we just warn. Only the keys the operator actually answered are written.
     */
    private function persistConfig(?string $prefix, ?string $sources, ?string $tenancy): void
    {
        if ($prefix === null && $sources === null && $tenancy === null) {
            return;
        }

        $path = config_path('beam/core.php');

        if (! is_file($path) || ! is_writable($path)) {
            $this->warn('beam:install — answered config kept for this run only; publish config/beam/core.php to persist it.');

            return;
        }

        $contents = (string) file_get_contents($path);

        if ($prefix !== null) {
            $contents = $this->replaceScalar($contents, 'table_prefix', $prefix);
        }
        if ($sources !== null) {
            $list = implode(', ', array_map(static fn (string $s): string => "'{$s}'", $this->parseList($sources)));
            $contents = preg_replace("/'sources'\s*=>\s*\[[^\]]*\]/", "'sources' => [{$list}]", $contents) ?? $contents;
        }

        file_put_contents($path, $contents);
    }

    private function replaceScalar(string $contents, string $key, string $value): string
    {
        return preg_replace("/'{$key}'\s*=>\s*'[^']*'/", "'{$key}' => '{$value}'", $contents) ?? $contents;
    }

    /** @return list<string> */
    private function parseList(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $s): bool => $s !== ''));
    }

    private function banner(): string
    {
        return <<<'ART'
  ┌────────────────────────────────────────┐
  │   ▓▓▓  BEAM  ▓▓▓   schema-driven CMS    │
  │   one command · core-first · retrofit   │
  └────────────────────────────────────────┘
ART;
    }
}
