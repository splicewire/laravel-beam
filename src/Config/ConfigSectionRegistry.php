<?php

namespace Splicewire\Beam\Config;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * Every Config Section this install declares, keyed by its config root (ADR-0245).
 *
 * VOCABULARY ONLY: an entry is a section's declaration (its class, owner and package file). Values, and later
 * overrides, are never entries: per-tenant values in a registry is what the registry-kernel rule forbids, and
 * an override is a database row the store owns.
 *
 * A package registers its own sections from its provider; a host registers sections for its own config roots
 * with `owner: 'host'`. One root has one section: a second registration for a root supersedes the first, which
 * is how a host replaces a package's section without forking it.
 *
 * @implements Registry<RegisteredConfigSection>
 */
#[IsRegistry(
    root: 'beam.config.sections',
    entryType: RegisteredConfigSection::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'Config Sections by config root (ADR-0245): the typed description of a package\'s or host\'s config, its keys\' ladder behaviour (ceiling, effect, secret, merge) and the file that holds its package defaults. Vocabulary only — values are read from config() and the config files, and overrides are store rows, never entries. One section per root; a later registration supersedes. Served by the `beam-config` particle resource.',
    order: 19,
)]
class ConfigSectionRegistry implements RecordsRegistrants, Registry
{
    /** @var BasicRegistry<RegisteredConfigSection> */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * @param  class-string<ConfigSection>|RegisteredConfigSection|RegistryKey|string  $key  a section class, a built entry, or a raw key with `$entry`
     */
    public function register(RegistryKey|string|RegisteredConfigSection $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (is_string($key) && is_subclass_of($key, ConfigSection::class)) {
            $key = new RegisteredConfigSection($key, owner: 'host');
        }

        if ($key instanceof RegisteredConfigSection) {
            $entry = $key;
            $key = self::keyFor($entry->root());
        }

        $this->store->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * Register a section with its owner and the file holding its package defaults.
     *
     * @param  class-string<ConfigSection>  $section
     */
    public function section(string $section, string $owner, ?string $packageFile = null, ?string $by = null): static
    {
        return $this->register(new RegisteredConfigSection($section, $owner, $packageFile), by: $by);
    }

    public function get(string $root): ?RegisteredConfigSection
    {
        // A caller-supplied root (a route id) the key grammar refuses names no section: absence, not an error.
        if (Key::tryParse($root) === null) {
            return null;
        }

        $entry = $this->store->tryResolve($root);

        return $entry instanceof RegisteredConfigSection ? $entry : null;
    }

    /**
     * Every registered section, ordered by root.
     *
     * @return list<RegisteredConfigSection>
     */
    public function all(): array
    {
        $sections = $this->store->matches($this->store->root());

        usort($sections, fn (RegisteredConfigSection $a, RegisteredConfigSection $b): int => strcmp($a->root(), $b->root()));

        return $sections;
    }

    /**
     * A config root is a dot path, and so is a registry key; each root segment becomes one key segment. A segment
     * the key grammar refuses is refused here by name rather than registered under a mangled key.
     */
    public static function keyFor(string $root): string
    {
        foreach (explode('.', $root) as $segment) {
            if (preg_match(Key::SEGMENT_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException(sprintf('Config root [%s] has a segment [%s] the registry key grammar does not admit.', $root, $segment));
            }
        }

        return $root;
    }

    /* ---------------- Registry contract ---------------- */

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    public function keys(): array
    {
        return $this->store->keys();
    }

    /**
     * The recording store itself; this registry is ungated, so the filtered and unfiltered reads agree.
     *
     * @return BasicRegistry<RegisteredConfigSection>
     */
    public function unfiltered(): BasicRegistry
    {
        return $this->store;
    }

    public function registrantOf(RegistryKey|string $key): ?string
    {
        // A malformed key is a miss, never a throw.
        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->store->registrantOf($key);
    }

    public function keysBy(string $registrant): array
    {
        return $this->store->keysBy($registrant);
    }
}
