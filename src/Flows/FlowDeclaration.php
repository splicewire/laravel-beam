<?php

namespace Splicewire\Beam\Flows;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\HasRegistryKey;

/**
 * One flow a package ships, as one entry in the `beam.flows` catalog (browser-journeys 02).
 *
 * A plain readonly value object — data and class-strings only, no closures — because it crosses no
 * wire: the doctor, the browser-test helper, the docs generator and console listing all read it
 * in-process (it is NOT a boundary-crossing Data shape, R1-Q1). It implements {@see HasRegistryKey}
 * so the catalog keys it off its own `key`, as `ParticleResource` does.
 *
 * It carries SIX author-typed facts; the seventh the design names — the declaring package — is NOT a
 * field here: it is the registrant `$by`, recorded by {@see FlowCatalog} at `declare()` time
 * (R3-Q4), so there is no typed package string to go stale. Three further facts are DERIVED, never
 * declared: the parent (from the key), the nav item (from the route names, by the doctor) and the
 * proving tests (from run records). No constructor parameter has a default — an omission and a
 * decision must not be spelled the same (R1-Q3).
 */
final class FlowDeclaration implements HasRegistryKey
{
    /**
     * @param  string  $key  `surface[.flow[.subflow…]]`; each segment `[a-z][a-z0-9]*(-[a-z0-9]+)*`
     *                       (no `_`, no `:`, no `--`, first char a letter). The first segment is the surface.
     * @param  string  $label  English literal; its text key is derived `<ns>::flows.<key as a/b/c>.label`
     * @param  list<string>  $routes  entry route NAMES; `[]` for a surface or a host-rendered page
     * @param  class-string<FlowGate>  $gate  {@see Gates\AlwaysOn}::class when there is no runtime switch
     * @param  class-string|null  $checkpoint  ticket 04's Checkpoint contract (deferred to ticket 16; this
     *                                         nullable field is the forward-compatible placeholder — narrows to
     *                                         `class-string<Checkpoint>` once 04/16 lands that interface). `null` is an explicit
     *                                         written decision, never a default.
     * @param  list<string>  $roles  demo-subject roles this flow is verified for; `[]` = a guest flow
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $routes,
        public readonly string $gate,
        public readonly ?string $checkpoint,
        public readonly array $roles,
    ) {
        if (! self::isValidKey($key)) {
            throw new InvalidArgumentException(
                "flow key '{$key}' is not valid: dot-separated segments, each matching ".
                "[a-z][a-z0-9]*(-[a-z0-9]+)* (no '_', ':' or '--'; a segment starts with a letter)."
            );
        }

        // A surface (single segment, e.g. `account`) declares the branch itself and names no routes.
        if (! str_contains($key, '.') && $routes !== []) {
            throw new InvalidArgumentException(
                "flow surface '{$key}' must declare no routes; entry routes belong to flows under a surface."
            );
        }
    }

    public function registryKey(): string
    {
        return $this->key;
    }

    /** The parent flow key — this key minus its last segment — or null for a surface (one segment). */
    public function parent(): ?string
    {
        $cut = strrpos($this->key, '.');

        return $cut === false ? null : substr($this->key, 0, $cut);
    }

    private static function isValidKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        foreach (explode('.', $key) as $segment) {
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }
}
