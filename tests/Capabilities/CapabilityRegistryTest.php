<?php

namespace Splicewire\Beam\Tests\Capabilities;

use Splicewire\Beam\Capabilities\CapabilityRegistry;
use Splicewire\Beam\Capabilities\GatedCapability;
use Splicewire\Beam\Tests\TestCase;

/**
 * The registry's two axes are separate (app ADR-0023), so only ACCESS is mandatory: a capability
 * may be gated yet spend nothing, and declares that by returning null from `meter()` (app
 * ADR-0205). ADR-0135's platform-hosted-but-deliberately-unmetered conformance run is that case;
 * before the meter went nullable it had nowhere to be declared.
 */
class CapabilityRegistryTest extends TestCase
{
    public function test_it_registers_a_gated_but_free_capability(): void
    {
        $registry = new CapabilityRegistry;

        $registry->register(new UnmeteredCapability);

        $capability = $registry->get('conformance');

        $this->assertNotNull($capability);
        $this->assertNull($capability->meter());
        $this->assertSame('conformance', $capability->requiredEntitlement());
    }

    public function test_it_still_registers_a_metered_capability(): void
    {
        $registry = new CapabilityRegistry;

        $registry->register(new MeteredCapability);

        $this->assertSame('google.cse.query', $registry->get('web_search')?->meter());
    }

    public function test_it_resolves_a_free_capability_by_entitlement(): void
    {
        $registry = new CapabilityRegistry;

        $registry->register(new UnmeteredCapability);

        $this->assertSame('conformance', $registry->byEntitlement('conformance')?->key());
    }
}

class UnmeteredCapability implements GatedCapability
{
    public function key(): string
    {
        return 'conformance';
    }

    public function meter(): ?string
    {
        return null;
    }

    public function requiredEntitlement(): string
    {
        return 'conformance';
    }
}

class MeteredCapability implements GatedCapability
{
    public function key(): string
    {
        return 'web_search';
    }

    /** Declared `string`, not `?string` — covariance keeps every existing implementer valid. */
    public function meter(): string
    {
        return 'google.cse.query';
    }

    public function requiredEntitlement(): string
    {
        return 'web_search';
    }
}
