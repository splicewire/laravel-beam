<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;

/**
 * `localization.english-derivation` (localization 12; coord-lead ruling (8) on 08; SPEC Resolution): a framed resource
 * whose label or singular, or an action whose label, is an English DERIVATION (a headlined key, an inflected singular,
 * a headlined op name) rather than a declaration. Localization 08 keeps the derivation as the English default only,
 * computed from English; this makes the reliance visible, since the derivation means nothing in another language's
 * grammar and a translator has no declared English to work from.
 */
final class LocalizationEnglishDerivationAudit implements DoctorAudit
{
    public const CHECK = 'localization.english-derivation';

    public const POPULATION = 'PHP-emitted keys only (client keys join with localization 16): the framed resources and '
        .'their action affordances this host registers.';

    public function __construct(private KeyCatalog $catalog) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $framed = 0;
        $findings = [];
        foreach ($this->catalog->entries() as $entry) {
            if (! in_array($entry->group(), ['resources', 'actions'], true)) {
                continue;
            }
            $framed++;
            if ($entry->derivedEnglish) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s renders the English derivation "%s" for [%s]: declare it (a `label:`/`singularLabel:` on the resource, a `label:` on the affordance).',
                    $entry->origin, $entry->english, $entry->key,
                ));
            }
        }

        if ($framed === 0) {
            return [Finding::inconclusive(self::CHECK, 'No framed resource or action affordance is registered on this host. '.self::POPULATION)];
        }

        return $findings !== [] ? $findings : [Finding::pass(self::CHECK, 'Every framed resource and action declares its English. '.self::POPULATION)];
    }
}
