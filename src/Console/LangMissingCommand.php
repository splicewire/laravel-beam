<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\Catalog\CatalogEntry;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\LocaleChain;
use Splicewire\Beam\Localization\Locales;

/**
 * The keys a locale is missing (localization 12, story 25): every catalog key with no line at that locale or its parent
 * languages (the source-language fallback does not count). With `--realm`, a line at that realm's rungs or the base
 * counts. Read-only; the export a translator works through.
 *   php artisan beam:lang:missing es-MX --json > missing-es-MX.json
 */
class LangMissingCommand extends Command
{
    protected $signature = 'beam:lang:missing
        {locale : the locale to export, e.g. es-MX}
        {--realm= : count a realm rung\'s lines as well}
        {--json : print JSON (population, then the missing entries)}';

    protected $description = 'Export the translation keys a locale is missing, with their English.';

    public function handle(KeyCatalog $catalog): int
    {
        $translator = $this->laravel->make('translator');
        if (! $translator instanceof BeamTranslator) {
            $this->error('The application translator is not beam\'s ladder.');

            return self::FAILURE;
        }

        $argument = $this->argument('locale');
        $locale = LocaleChain::canonical(is_string($argument) ? $argument : '');
        $realmOption = $this->option('realm');
        $realm = is_string($realmOption) && $realmOption !== '' ? $realmOption : null;

        $missing = self::missing($catalog, $translator, $locale, $realm);
        $note = Locales::isPseudo($locale)
            ? 'The pseudo-locale derives every line from English, so it is missing nothing by construction.'
            : null;

        if ($this->option('json')) {
            $this->line((string) json_encode(array_filter([
                'population' => KeyCatalog::POPULATION,
                'locale' => $locale,
                'realm' => $realm,
                'note' => $note,
                'missing' => array_map(static fn (CatalogEntry $e): array => ['key' => $e->key, 'english' => $e->english], $missing),
            ], static fn ($v): bool => $v !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('Population: '.KeyCatalog::POPULATION);
        if ($note !== null) {
            $this->info($note);
        }
        $this->table(['key', 'English'], array_map(static fn (CatalogEntry $e): array => [$e->key, $e->english], $missing));
        $this->line(sprintf('%s%s: %d missing of %d.', $locale, $realm === null ? '' : " (realm {$realm})", count($missing), count($catalog->entries())));

        return self::SUCCESS;
    }

    /** @return list<CatalogEntry> */
    public static function missing(KeyCatalog $catalog, BeamTranslator $translator, string $locale, ?string $realm = null): array
    {
        if (Locales::isPseudo($locale)) {
            return [];
        }

        return array_values(array_filter(
            $catalog->entries(),
            static fn (CatalogEntry $entry): bool => $translator->resolve($entry->key, $locale, false, $realm) === null,
        ));
    }
}
