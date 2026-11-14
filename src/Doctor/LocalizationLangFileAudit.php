<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Catalog\LangFileLines;

/**
 * Two checks over the PHP lang files a host can load (localization 12), against the key catalog:
 *
 * - `localization.realm-line-without-base`: a realm rung line whose key the base does not define (SPEC: "a realm file
 *   may only shadow a key the base defines"; for a derived key the base is the declaration). A string that exists in
 *   one realm only.
 * - `localization.underived-key`: a line in a derived group (`resources`, `data`, `enums`, `actions`, `nav`, `groups`, `realms`)
 *   that no declaration derives: a stale or mistyped override that silently answers nothing.
 *
 * Advisory: what is declared depends on what the host has installed.
 */
final class LocalizationLangFileAudit implements DoctorAudit
{
    public const REALM = 'localization.realm-line-without-base';

    public const UNDERIVED = 'localization.underived-key';

    public const POPULATION = 'PHP-emitted and CLIENT keys (localization 16: the discovered catalog\'s, JS-only packages included): every line in the host\'s '
        .'lang/<locale>, lang/vendor/<ns>/<locale> and each family package\'s lang/<locale> PHP files, base and realm '
        .'rungs, against the key catalog. Nav page titles and group names have no catalog population yet and get no verdict.';

    public function __construct(
        private KeyCatalog $catalog,
        private LangFileLines $lines,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $lines = $this->lines->all();
        if ($lines === []) {
            return [
                Finding::inconclusive(self::REALM, 'This host loads no PHP lang file. '.self::POPULATION),
                Finding::inconclusive(self::UNDERIVED, 'This host loads no PHP lang file. '.self::POPULATION),
            ];
        }

        $known = $this->catalog->byKey();
        $realm = [];
        $underived = [];
        foreach ($lines as $line) {
            $isDerived = in_array($line['group'], KeyCatalog::DERIVED_GROUPS, true);
            // Nav sections and realm labels are in the catalog (localization 11). Nav page titles are keyed by their
            // authoring code (beam-ux's projection, the host's pages) and a group's name is emitted by nothing yet, so
            // neither has a catalog population: no verdict on them.
            $item = substr($line['key'], (int) strrpos($line['key'], '::') + (str_contains($line['key'], '::') ? 2 : 0));
            if ($isDerived && ($line['group'] === 'groups' || str_starts_with($item, 'nav.routes.'))) {
                continue;
            }
            if ($isDerived && ! isset($known[$line['key']])) {
                $underived[] = Finding::warn(self::UNDERIVED, sprintf(
                    '%s defines [%s] (%s%s), but no declaration on this host derives that key, so the line answers nothing. Fix the key or remove the line.',
                    $line['file'], $line['key'], $line['locale'], $line['realm'] === null ? '' : ", realm {$line['realm']}",
                ));

                continue;
            }
            if ($line['realm'] !== null && ! isset($known[$line['key']])) {
                $realm[] = Finding::warn(self::REALM, sprintf(
                    '%s (realm %s, %s) defines [%s], which the base does not: a string that exists in one realm only. Add its English to the base, or remove it.',
                    $line['file'], $line['realm'], $line['locale'], $line['key'],
                ));
            }
        }

        return [
            ...($realm !== [] ? $realm : [Finding::pass(self::REALM, sprintf('Every realm rung line shadows a base key (%d lines read). %s', count($lines), self::POPULATION))]),
            ...($underived !== [] ? $underived : [Finding::pass(self::UNDERIVED, sprintf('Every derived-group line names a declared key (%d lines read). %s', count($lines), self::POPULATION))]),
        ];
    }
}
