<?php

namespace Splicewire\Beam\Localization;

use Composer\InstalledVersions;
use Illuminate\Translation\MessageSelector;
use JsonException;

/**
 * The plural fixture: what the INSTALLED framework selector does, as one committed, byte-stable table (localization
 * ruling 02 §2, ticket 07). PHP and the client message seam (ticket 15) evaluate the same table, so the client picks the
 * form the server would; beam's test compares the installed selector against the committed copy, so a framework upgrade
 * that changes plural rules fails loudly instead of drifting. The format is FIXTURE-FORMAT.md's (the 07 handback).
 *
 * - `aliases`: every locale alias the selector's `getPluralIndex()` switch names, in source order.
 * - `counts`: 0 to 200, then 0.5 and 1.5.
 * - `indexes`: alias => the selector's index for each count, in `counts` order.
 * - `normalization`: requested locales and the alias {@see PluralLocale} hands the selector (full alias, then
 *   language subtag, else unchanged so the selector's default index 0 applies).
 * - `baseline`: the framework version and the sha256 of the selector source the table was generated from. Provenance:
 *   the comparison is of the rules (aliases and indexes), so a selector file that changes without changing a rule
 *   passes.
 *
 * Serialized as RFC 8785 (JCS): object keys sorted, no insignificant whitespace, UTF-8, no trailing newline.
 */
final class PluralSelectorTable
{
    public const VERSION = 1;

    /** The normalization cases the fixture carries: requested locale => what reaches the selector. */
    public const NORMALIZATION_CASES = [
        'en', 'en-US', 'en_US', 'es', 'es-MX', 'es-419', 'pt-BR', 'pt_BR', 'pt-PT', 'zh-Hant-TW', 'sr-Latn-RS',
        'ru-RU', 'ar-EG', 'ja', 'fr-CA', 'xx', 'xx-YY',
    ];

    /** @return list<int|float> */
    public static function counts(): array
    {
        return [...range(0, 200), 0.5, 1.5];
    }

    /**
     * The table as the installed selector produces it.
     *
     * @return array<string, mixed>
     */
    public static function fromInstalled(): array
    {
        $selector = new MessageSelector;
        $aliases = PluralLocale::aliases();
        $counts = self::counts();

        $indexes = [];
        foreach ($aliases as $alias) {
            $indexes[$alias] = array_map(static fn (int|float $n): int => $selector->getPluralIndex($alias, $n), $counts);
        }

        $normalization = [];
        foreach (self::NORMALIZATION_CASES as $locale) {
            $normalization[] = ['alias' => PluralLocale::forSelector($locale), 'locale' => $locale];
        }

        return [
            'aliases' => $aliases,
            'baseline' => [
                'framework' => 'laravel/framework '.(InstalledVersions::getPrettyVersion('laravel/framework') ?? 'unknown'),
                'selector' => 'src/Illuminate/Translation/MessageSelector.php',
                'sha256' => PluralLocale::selectorSourceSha256(),
            ],
            'counts' => $counts,
            'indexes' => $indexes,
            'normalization' => $normalization,
            'version' => self::VERSION,
        ];
    }

    /**
     * RFC 8785 (JCS) for this table's value space: objects with keys sorted by code point, arrays in order, ASCII
     * strings, integers, and the two finite floats 0.5 and 1.5. No whitespace and no trailing newline.
     *
     * @param  array<string, mixed>  $table
     *
     * @throws JsonException
     */
    public static function encode(array $table): string
    {
        return json_encode(self::sortKeys($table), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sortKeys(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sortKeys(...), $value);
    }
}
