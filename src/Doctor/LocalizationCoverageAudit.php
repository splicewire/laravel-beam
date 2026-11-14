<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\LangMissingCommand;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Catalog\LangFileLines;
use Splicewire\Beam\Localization\LocaleChain;
use Splicewire\Beam\Localization\Locales;

/**
 * `localization.coverage` (localization 12, story 25): for each language this host ships other than the source and
 * the pseudo-locale, how many catalog keys have a line, at the base and at each realm rung its files define. Coverage is
 * a number, not a feeling: a full base passes, anything less warns with the count and `beam:lang:missing` to export.
 */
final class LocalizationCoverageAudit implements DoctorAudit
{
    public const CHECK = 'localization.coverage';

    public function __construct(
        private KeyCatalog $catalog,
        private LangFileLines $lines,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $translator = app('translator');
        $locales = array_values(array_filter(
            Locales::supported(),
            static fn (string $l): bool => ! Locales::isPseudo($l) && $l !== LocaleChain::canonical((string) config('beam.localization.fallback', 'en')),
        ));

        if (! $translator instanceof BeamTranslator || $locales === []) {
            return [Finding::inconclusive(self::CHECK, 'This host ships no language other than its source (and the pseudo-locale), so there is no coverage to measure. '.KeyCatalog::POPULATION)];
        }

        $total = count($this->catalog->entries());
        if ($total === 0) {
            return [Finding::inconclusive(self::CHECK, 'The key catalog is empty on this host. '.KeyCatalog::POPULATION)];
        }

        $realms = [];
        foreach ($this->lines->all() as $line) {
            if ($line['realm'] !== null) {
                $realms[LocaleChain::canonical($line['locale'])][$line['realm']] = true;
            }
        }

        $findings = [];
        foreach ($locales as $locale) {
            $missing = count(LangMissingCommand::missing($this->catalog, $translator, $locale));
            $parts = [sprintf('%s: %d of %d keys (%.1f%%) at the base', $locale, $total - $missing, $total, 100 * ($total - $missing) / $total)];
            foreach (array_keys($realms[$locale] ?? []) as $realm) {
                $realmMissing = count(LangMissingCommand::missing($this->catalog, $translator, $locale, $realm));
                $parts[] = sprintf('%d with realm %s', $total - $realmMissing, $realm);
            }
            $text = implode('; ', $parts).'. '.KeyCatalog::POPULATION;
            $export = $missing === 0 ? '' : sprintf(' Export the %d missing with `php artisan beam:lang:missing %s`.', $missing, $locale);
            // B11-B6: a partial client population is never read as complete coverage.
            $partial = $this->catalog->clientDiscoveryLine();
            $findings[] = $missing === 0 && $partial === null
                ? Finding::pass(self::CHECK, $text)
                : Finding::warn(self::CHECK, $text.$export.($partial === null ? '' : ' '.$partial));
        }

        return $findings;
    }
}
