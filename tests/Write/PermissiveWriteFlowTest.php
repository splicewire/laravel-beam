<?php

namespace Splicewire\Beam\Tests\Write;

use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\PermissiveAcceptanceGate;
use Splicewire\Beam\Write\PermissiveWriteGate;

class PermissiveWriteFlowTest extends TestCase
{
    public function test_the_permissive_write_gate_always_authorizes(): void
    {
        $gate = new PermissiveWriteGate;

        $this->assertTrue($gate->authorizes('any-schema-stem', ['field' => 'value']));
        $this->assertTrue($gate->authorizes('any-schema-stem', [], null));
    }

    public function test_the_permissive_acceptance_gate_always_accepts_even_a_non_conforming_candidate(): void
    {
        $gate = new PermissiveAcceptanceGate;

        $this->assertTrue($gate->accepts(['field' => 'value'], ['type' => 'object', 'required' => ['missing']]));
        $this->assertTrue($gate->accepts([], []));
    }
}
