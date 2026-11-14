<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Localization\Catalog\CatalogEntry;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;

/**
 * Every key this host can emit, with its English (localization 12, story 24): the file a translator starts from.
 * Read-only.
 *   php artisan beam:lang:keys
 *   php artisan beam:lang:keys --json > keys.json
 */
class LangKeysCommand extends Command
{
    protected $signature = 'beam:lang:keys {--json : print JSON (population, then entries)}';

    protected $description = 'List every translation key this host can emit, with its English.';

    public function handle(KeyCatalog $catalog): int
    {
        $entries = $catalog->entries();

        if ($this->option('json')) {
            $this->line((string) json_encode(array_filter([
                'population' => KeyCatalog::POPULATION,
                // B11-B6, additive: the client population's actual input state.
                'clientDiscovery' => $catalog->clientDiscovery(),
                'entries' => array_map(static fn (CatalogEntry $e): array => $e->toArray(), $entries),
            ], static fn ($v): bool => $v !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('Population: '.KeyCatalog::POPULATION);
        if (($partial = $catalog->clientDiscoveryLine()) !== null) {
            $this->warn($partial);
        }
        $this->table(['key', 'English', 'source'], array_map(
            static fn (CatalogEntry $e): array => [$e->key, $e->english, $e->source.($e->derivedEnglish ? ' (English derived)' : '')],
            $entries,
        ));
        $this->line(sprintf('%d key%s.', count($entries), count($entries) === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
