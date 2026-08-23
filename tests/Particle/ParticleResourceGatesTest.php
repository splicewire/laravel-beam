<?php

namespace Splicewire\Beam\Tests\Particle;

use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * How a {@see ParticleResource} projects the four Frame gates onto the agnostic
 * {@see ResourceDefinition} (ADR-0156 §83 + F04). `readOnly` closes the three
 * WRITE gates (create/edit/delete) while `showable` — the F04 show-independent widening — stays open by
 * default, so a read-only INSPECT resource still serves a per-record detail (`records/{id}`).
 */
class ParticleResourceGatesTest extends TestCase
{
    private function resource(array $overrides = []): ParticleResource
    {
        return new ParticleResource(...array_merge([
            'key' => 'widgets',
            'backing' => 'App\\Models\\Widget',
            'data' => WidgetGateData::class,
            'label' => 'Widgets',
        ], $overrides));
    }

    public function test_a_default_writable_resource_opens_all_four_gates(): void
    {
        $def = $this->resource()->toResourceDefinition();

        $this->assertTrue($def->creatable);
        $this->assertTrue($def->editable);
        $this->assertTrue($def->deletable);
        $this->assertTrue($def->showable);
    }

    public function test_a_readonly_resource_closes_the_three_write_gates_but_stays_showable(): void
    {
        // F04: readOnly is an INSPECT surface — no create/edit/delete — yet the per-record detail (show)
        // stays open, so `operator-customers` regains its customer detail (ticket 07 regression).
        $def = $this->resource(['readOnly' => true])->toResourceDefinition();

        $this->assertFalse($def->creatable);
        $this->assertFalse($def->editable);
        $this->assertFalse($def->deletable);
        $this->assertTrue($def->showable, 'a readOnly resource is still showable by default (F04)');
    }

    public function test_showable_is_independent_and_can_be_closed_for_a_list_only_resource(): void
    {
        $def = $this->resource(['readOnly' => true, 'showable' => false])->toResourceDefinition();

        $this->assertFalse($def->showable, 'an explicit showable:false makes the resource genuinely list-only');
    }

    public function test_editable_and_deletable_remain_independently_overridable(): void
    {
        // The pre-existing edit/delete-independent widening still holds alongside the new show gate.
        $def = $this->resource(['editable' => false, 'deletable' => true])->toResourceDefinition();

        $this->assertTrue($def->creatable);
        $this->assertFalse($def->editable);
        $this->assertTrue($def->deletable);
        $this->assertTrue($def->showable);
    }
}
