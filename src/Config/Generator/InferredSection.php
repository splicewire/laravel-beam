<?php

namespace Splicewire\Beam\Config\Generator;

/**
 * A Config Section the generator inferred from a config file and the merged array (config-sections ticket 06), before
 * it is printed: the section class, the Data class each record or map entry becomes, and every key's attributes.
 *
 * `classes` maps a short class name to its keys, the section class first. A map-shaped root names its entry class in
 * `entries`, and the section class then has no keys.
 */
class InferredSection
{
    /**
     * @param  array<string, list<InferredKey>>  $classes
     * @param  array<string, string>  $pointers  short class name => the JSON pointer it describes ('' for the root)
     */
    public function __construct(
        public string $root,
        public string $title,
        public string $sectionClass,
        public ?string $entries,
        public array $classes,
        public array $pointers,
    ) {}
}
