<?php

namespace Splicewire\Beam\Capabilities;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

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
#[IsRegistry(
    root: 'beam.capabilities',
    of: 'gated capabilities by key + entitlement (web search, schema-LLM migration, node types)',
    arity: RegistryArity::PickOne,
    entryType: GatedCapability::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'THREE estate classes are named CapabilityRegistry — this one, tower\'s SUBCLASS of it, and the '
        .'unrelated Tower\Circuit\Capabilities one. That collision is why the index keys on ROOT and not '
        .'on short name. Attributes are not inherited by reflection, so tower\'s subclass declares its own '
        .'root and its own OnDuplicate::Admit; a host binding the subclass has ONE live object answering '
        .'to two declared roots, which is a migration question for registry-kernel ticket 37, not a '
        .'declaration one.',
    order: 15,
)]
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
