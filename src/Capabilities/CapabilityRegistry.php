<?php

namespace Splicewire\Beam\Capabilities;

/**
 * Generic registry of {@see ExternalCapability} declarations (app ADR-0023).
 *
 * The single lookup the Gate and Tenant Sync pre-flight share. This beam-core
 * base carries the register()/get()/byEntitlement()/all() machinery typed
 * against the beam {@see ExternalCapability} contract; it registers NOTHING by
 * default. A host subclass overrides {@see registerDefaults()} to seed the
 * concrete capabilities it ships (e.g. the tower registry registers Web Search
 * and Schema LLM Migration).
 */
class CapabilityRegistry
{
    /** @var array<string, ExternalCapability> */
    private array $byKey = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Overridable hook: seed the concrete capabilities a host ships.
     *
     * Empty in beam-core — beam mints no capability of its own. Subclasses
     * override to register their {@see ExternalCapability} implementations.
     */
    protected function registerDefaults(): void
    {
        // no-op: beam-core registers nothing.
    }

    public function register(ExternalCapability $capability): void
    {
        $this->byKey[$capability->key()] = $capability;
    }

    public function get(string $key): ?ExternalCapability
    {
        return $this->byKey[$key] ?? null;
    }

    public function byEntitlement(string $entitlement): ?ExternalCapability
    {
        foreach ($this->byKey as $capability) {
            if ($capability->requiredEntitlement() === $entitlement) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return list<ExternalCapability>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }
}
