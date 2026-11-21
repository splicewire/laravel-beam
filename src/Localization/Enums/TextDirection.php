<?php

namespace Splicewire\Beam\Localization\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The writing direction of a locale's script, as served on the client catalog's wire (ticket 19;
 * localization ruling 02 §4). The set is fixed in code by {@see \Splicewire\Beam\Localization\Client\ClientCatalogResolver::direction()}'s
 * two-value branch (decision 03, F6): a locale's primary language is right-to-left or it is not — no host,
 * tenant or registry can add a third. The case values are the two strings the catalog has always served, so
 * the wire shape and the catalog content hash do not change when the property is retyped to this enum.
 */
#[TypeScript]
enum TextDirection: string
{
    case Ltr = 'ltr';
    case Rtl = 'rtl';
}
