<?php

namespace Splicewire\Beam\Config\Attributes;

use Attribute;

/**
 * Marks a key's `#[Title]` and `#[Description]` as a DRAFT that `splicewire:beam:make:config-section` harvested from the
 * config file's comments (config-sections ticket 06). Those comments are often ADR history rather than operator copy, so
 * the draft is a starting point to rewrite, and {@see \Splicewire\Beam\Doctor\ConfigSectionCoverageAudit} reports every
 * key that still carries it. Delete the attribute once the copy is rewritten.
 *
 * The generator's combobox hint is `#[Keyword('x-widget', 'combobox')]` with `#[Keyword('x-widget-options', …)]`, whose
 * shape data-schemas leaves to the declarer. Beam's two forms are `{keysOf: <JSON pointer of a sibling map>}` (the
 * options are that map's keys) and `{registry: <registry root>}` (the options are that registry's keys).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ConfigDraft
{
    public function __construct(
        public string $source = 'harvested',
    ) {}
}
