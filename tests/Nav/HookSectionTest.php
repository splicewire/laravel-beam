<?php

namespace Splicewire\Beam\Tests\Nav;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/** ux-walkthrough UX-09 (IA-10; lead 10:04Z): Hooks sits in the operator rail's System task section, not 'platform'. */
class HookSectionTest extends TestCase
{
    public function test_hooks_sit_in_the_system_task_section(): void
    {
        $resource = (new ReflectionClass(HookData::class))->getAttributes(ParticleResource::class)[0]->newInstance();

        $this->assertSame('system', $resource->section);
    }
}
