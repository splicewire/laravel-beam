<?php

namespace Splicewire\Beam\Config\Generator;

/**
 * One key the generator inferred (config-sections ticket 06): the property it becomes and the attributes it carries.
 *
 * - `type`: the PHP type declaration (`bool`, `?string`, `int|string`, `array`, `mixed`, or a sibling class's short name).
 * - `optional`: absent from some entries of a map, so typed `|Optional`.
 * - `mapValues` / `arrayItems`: the `#[MapValues]` / `#[ArrayItems]` argument; `''` is a bare `#[MapValues]`.
 * - `widget`: the `x-widget-options` of a combobox (`['keysOf' => '/models']` or `['registry' => 'beam.x']`).
 * - `mapName`: the wire key when the config key is not a PHP identifier (`recipient-kinds`); `name` is then the mapped
 *   identifier (`recipientKinds`) and the property carries `#[MapName($mapName)]` (ticket 13, Part 2). Null when the
 *   config key is already an identifier and answers to its own name on the wire.
 */
class InferredKey
{
    /**
     * @param  list<string|int|float>|null  $enum
     * @param  array<string, string>|null  $widget
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $optional = false,
        public ?string $env = null,
        public string $title = '',
        public string $description = '',
        public ?array $enum = null,
        public ?array $widget = null,
        public ?string $mapValues = null,
        public ?string $arrayItems = null,
        public ?string $opaque = null,
        public ?string $mapName = null,
    ) {}
}
