<?php

namespace Splicewire\Beam\Config;

use InvalidArgumentException;

/**
 * A {@see ConfigSectionRegistry} entry: which section class describes a root, who owns it, and where its package
 * defaults live.
 *
 * - `owner`: the composer name of the declaring package, or `host` for a root the host declares for itself.
 * - `packageFile`: the package's own config file for this root (the one its provider passes to
 *   `mergeConfigFrom()`). Null for a host-owned root, which has no package tier.
 */
class RegisteredConfigSection
{
    /**
     * @param  class-string<ConfigSection>  $section
     */
    public function __construct(
        public string $section,
        public string $owner,
        public ?string $packageFile = null,
    ) {
        if (! is_subclass_of($section, ConfigSection::class)) {
            throw new InvalidArgumentException(sprintf('[%s] is not a %s.', $section, ConfigSection::class));
        }
    }

    public function root(): string
    {
        return $this->section::root();
    }
}
