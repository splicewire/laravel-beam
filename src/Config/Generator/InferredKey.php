<?php

namespace Splicewire\Beam\Config\Generator;

/**
 * One key the generator inferred (config-sections ticket 06): the property it becomes and the attributes it carries.
 *
 * - `type`: the PHP type declaration (`bool`, `?string`, `int|string`, `array`, `mixed`, or a sibling class's short name).
 * - `optional`: absent from some entries of a map, so typed `|Optional`.
 * - `mapValues` / `arrayItems`: the `#[MapValues]` / `#[ArrayItems]` argument; `''` is a bare `#[MapValues]`.
 * - `widget`: the `x-widget-options` of a combobox (`['keysOf' => '/models']` or `['registry' => 'beam.x']`).
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
    ) {}
}
