<?php

namespace Splicewire\Beam\Capabilities;

/**
 * Generic registry of {@see GatedCapability} declarations (app ADR-0023).
 *
 * The single lookup the Gate and Tenant Sync pre-flight share. This beam-core
 * base carries the register()/get()/byEntitlement()/all() machinery typed
 * against the beam {@see GatedCapability} contract; it registers NOTHING by
 * default. A host subclass overrides {@see registerDefaults()} to seed the
 * concrete capabilities it ships (e.g. the tower registry registers Web Search
 * and Schema LLM Migration).
 */
class CapabilityRegistry
{
    /** @var array<string, GatedCapability> */
    private array $byKey = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Overridable hook: seed the concrete capabilities a host ships.
     *
     * Empty in beam-core — beam mints no capability of its own. Subclasses
     * override to register their {@see GatedCapability} implementations.
     */
    protected function registerDefaults(): void
    {
        // no-op: beam-core registers nothing.
    }

    public function register(GatedCapability $capability): void
    {
        $this->byKey[$capability->key()] = $capability;
    }

    public function get(string $key): ?GatedCapability
    {
        return $this->byKey[$key] ?? null;
    }

    public function byEntitlement(string $entitlement): ?GatedCapability
    {
        foreach ($this->byKey as $capability) {
            if ($capability->requiredEntitlement() === $entitlement) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return list<GatedCapability>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }
}
