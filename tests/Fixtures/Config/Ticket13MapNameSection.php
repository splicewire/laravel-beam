<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * Fixture (config-sections ticket 13 — the property-`#[MapName]` case, condition 2, and the Part 4 empty-map shape).
 *
 * - `hostRoutes` carries a property-level `#[MapName('host-routes')]`, so its wire name is `host-routes`, and a
 *   `#[MapValues('string')]` making it a map. The schema's `host-routes` property is the Part 4 `anyOf` that admits
 *   both the `{type: object}` map and an empty `[]`, and coverage keyed on `host-routes` must resolve the same
 *   property through {@see \Splicewire\Beam\Config\ConfigWireNames}.
 * - `enabled` is an ordinary required key, a plain identifier (no mapping).
 */
#[ConfigRoot(root: 'cs13.mapname', title: 'MapName section')]
class Ticket13MapNameSection extends ConfigSection
{
    /** @param  array<string, string>  $hostRoutes */
    public function __construct(
        #[Title('Host routes'), Description('A host-keyed map of route names.'), ConfigKey, MapValues('string'), MapName('host-routes')]
        public array $hostRoutes,
        #[Title('Enabled'), Description('Whether the section is on.'), ConfigKey]
        public bool $enabled,
    ) {}
}
