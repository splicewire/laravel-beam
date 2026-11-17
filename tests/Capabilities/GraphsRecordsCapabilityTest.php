<?php

namespace Splicewire\Beam\Tests\Capabilities;

use LogicException;
use Rushing\Graphine\Contracts\GraphStore;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\GraphsRecords;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Tests\TestCase;

/**
 * The graph capability is decided the same way every backing capability is (ticket 01, done-when):
 * {@see BackingResolver::hasCapability()} is a static instanceof check, so a backing that implements
 * {@see GraphsRecords} HAS it and a bare {@see ResourceBacking} does not. No backing is resolved and
 * `graph()` is never called here — the fixture's body throws to prove that.
 */
class GraphsRecordsCapabilityTest extends TestCase
{
    public function test_a_backing_that_implements_the_port_has_the_graph_capability(): void
    {
        $resolver = new BackingResolver;

        $graphBacking = new class implements GraphsRecords
        {
            public function graph(array $filters): GraphStore
            {
                throw new LogicException('fixture backing — graph() is never called by hasCapability()');
            }
        };

        $this->assertTrue($resolver->hasCapability($graphBacking, GraphsRecords::class));
    }

    public function test_a_backing_that_does_not_implement_the_port_lacks_the_graph_capability(): void
    {
        $resolver = new BackingResolver;

        $plainBacking = new class implements ResourceBacking {};

        $this->assertFalse($resolver->hasCapability($plainBacking, GraphsRecords::class));
    }
}
