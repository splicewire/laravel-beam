<?php

namespace Splicewire\Beam\Config\Attributes;

use Attribute;

/**
 * Names the config root a {@see \Splicewire\Beam\Config\ConfigSection} describes (ADR-0245).
 *
 * - `root`: the dot path `config()` reads (`beam.core`, `app.meters`).
 * - `title` / `description`: operator-facing copy for the Config area and the section's JSON Schema.
 * - `entries`: set for a MAP-shaped root, whose top-level keys are names a host chooses (`app.meters` is keyed
 *   by meter name). Every value is one instance of the named Data class, and the section class itself declares
 *   no properties. Left null, the root is RECORD-shaped and the section's own properties are its keys.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ConfigRoot
{
    /**
     * @param  class-string<\Spatie\LaravelData\Data>|null  $entries
     */
    public function __construct(
        public string $root,
        public string $title,
        public string $description = '',
        public ?string $entries = null,
    ) {}
}
