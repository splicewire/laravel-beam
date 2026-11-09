<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;

/**
 * `splicewire:beam:config:schemas` — writes each registered Config Section's JSON Schema to the host's committed
 * schema tree (`resources/schemas/config-sections/<root>.schema.json`), ADR-0242.
 *
 * Regeneration is this command; verification is a test or `--check`, and neither writes. `--check` exits 1 and
 * names every missing or stale file, which is the drift signal a CI gate reads.
 *
 * `--owner` narrows the run to one owner's sections (`host`, or a package's composer name), because a host commits
 * the schemas of the roots it declares and a package commits its own.
 */
class ConfigSectionSchemasCommand extends Command
{
    protected $signature = 'splicewire:beam:config:schemas
                            {--check : Report missing or stale schema files and write nothing (exit 1 on any)}
                            {--owner= : Only the sections this owner registered (host, or a composer name)}
                            {--path= : The directory to write into (default: resources/schemas/config-sections)}';

    protected $description = 'Write (or --check) the committed JSON Schema of every registered Config Section.';

    public function handle(ConfigSectionRegistry $registry, ConfigSectionSchema $schemas, Filesystem $files): int
    {
        $owner = $this->option('owner');
        $directory = is_string($this->option('path')) && $this->option('path') !== '' ? (string) $this->option('path') : null;

        $sections = array_values(array_filter(
            $registry->all(),
            fn ($section): bool => ! is_string($owner) || $owner === '' || $section->owner === $owner,
        ));

        if ($sections === []) {
            $this->components->warn('No Config Sections are registered'.(is_string($owner) && $owner !== '' ? " for owner [{$owner}]" : '').'.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $drift = $schemas->drift($sections, $directory);

            foreach ($drift as $root => $reason) {
                $this->components->error("{$root} — {$reason}");
            }

            if ($drift !== []) {
                $this->line('Regenerate with: php artisan splicewire:beam:config:schemas'.(is_string($owner) && $owner !== '' ? " --owner={$owner}" : ''));

                return self::FAILURE;
            }

            $this->components->info(count($sections).' Config Section schema(s) current.');

            return self::SUCCESS;
        }

        foreach ($sections as $section) {
            $path = ConfigSectionSchema::path($section->root(), $directory);
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, ConfigSectionSchema::encode($schemas->for($section->section)));
            $this->components->twoColumnDetail($section->root(), str_replace(base_path().'/', '', $path));
        }

        return self::SUCCESS;
    }
}
