<?php

namespace Splicewire\Beam\Tests\Config;

use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Schema\IdentifierLabels;
use Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard\Elsewhere\ProbeScope;
use Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard\ProbeConfigOp;
use Splicewire\Beam\Tests\TestCase;

/**
 * The config area's own identifier-label ratchet (config-sections 05 fix-forward; root e8fef488). A host's
 * IdentifierLabelTest (`AssertsIdentifierLabels`) fails on any class reachable from a particle that renders an identifier
 * without a #[Title] (or, for an enum, ProvidesEnumLabel) and is not on that host's list. 05 shipped five such classes,
 * and every host that repinned laravel-beam went red.
 *
 * The guard walks the host's own walk ({@see IdentifierLabels::findFrom()}) from the `beam-config` resource and EVERY
 * `#[ParticleOp(resource: 'beam-config')]` declared anywhere under src/, found by its attribute, not its file name or
 * folder. It keeps every unlabelled class those roots reach, whatever its namespace, and asserts the set is exactly
 * ticket 02's nine, which hosts already list with their owner (laravel-beam 5a4a076 / a48aec4). The list only shrinks: a
 * class that gains a label must leave it, and the hosts' lists with it.
 */
class ConfigIdentifierLabelTest extends TestCase
{
    /** Ticket 02's label-less config classes, as the hosts' IdentifierLabelTest lists them. */
    private const KNOWN = [
        'Splicewire\Beam\Data\Config\ConfigEnvData',
        'Splicewire\Beam\Data\Config\ConfigKeyData',
        'Splicewire\Beam\Data\Config\ConfigLayerData',
        'Splicewire\Beam\Data\Config\ConfigSectionCountsData',
        'Splicewire\Beam\Data\Config\ConfigSectionData',
        'Splicewire\Beam\Data\Config\ConfigSectionFilesData',
        'Splicewire\Beam\Enums\ConfigEffect',
        'Splicewire\Beam\Enums\ConfigMerge',
        'Splicewire\Beam\Enums\ConfigTier',
    ];

    public function test_every_class_the_config_particle_reaches_declares_its_label_or_is_a_listed_02_class(): void
    {
        $unlabelled = $this->unlabelled($this->configOps());

        $this->assertSame([], array_values(array_diff($unlabelled, self::KNOWN)), 'A class the beam-config particle reaches renders an identifier with no label: declare #[Title] (or ProvidesEnumLabel on an enum).');
        $this->assertSame([], array_values(array_diff(self::KNOWN, $unlabelled)), 'Stale: a listed 02 class now declares its label (or is unreachable); drop it here AND from the hosts\' IdentifierLabelTest lists.');
    }

    public function test_finds_the_config_ops_by_their_attribute(): void
    {
        $this->assertSame([
            'Splicewire\Beam\Particle\Ops\Config\ConfigVersionsOp',
            'Splicewire\Beam\Particle\Ops\Config\DiscardConfigDraftOp',
            'Splicewire\Beam\Particle\Ops\Config\LockConfigKeyOp',
            'Splicewire\Beam\Particle\Ops\Config\PublishConfigOp',
            'Splicewire\Beam\Particle\Ops\Config\RestoreConfigVersionOp',
            'Splicewire\Beam\Particle\Ops\Config\SaveConfigDraftOp',
        ], $this->configOps());
    }

    public function test_an_unlabelled_class_outside_the_config_namespaces_that_a_config_op_reaches_fails_the_guard(): void
    {
        $unlabelled = $this->unlabelled([...$this->configOps(), ProbeConfigOp::class]);

        $this->assertSame([ProbeScope::class], array_values(array_diff($unlabelled, self::KNOWN)));
    }

    /**
     * @param  list<class-string>  $ops
     * @return list<class-string> sorted
     */
    private function unlabelled(array $ops): array
    {
        $roots = [];

        foreach ($ops as $op) {
            $declared = (new ReflectionClass($op))->getAttributes(ParticleOp::class)[0]->newInstance();
            // As IdentifierLabels::roots() reads a booted op: the input, and the output as one class or per-status
            // payloads (each a class or a list of classes).
            $classes = [$declared->input];
            if (is_array($declared->output)) {
                foreach ($declared->output as $payloads) {
                    array_push($classes, ...array_values((array) $payloads));
                }
            } else {
                $classes[] = $declared->output;
            }
            foreach ($classes as $class) {
                if (is_string($class)) {
                    $roots[] = $class;
                }
            }
        }

        $resource = (new ReflectionClass(ConfigSectionData::class))->getAttributes(ParticleResource::class)[0]->newInstance();
        $this->assertSame('beam-config', $resource->key);
        $roots[] = ConfigSectionData::class;
        if (is_string($resource->input)) {
            $roots[] = $resource->input;
        }

        $unlabelled = array_keys(app(IdentifierLabels::class)->findFrom(array_values(array_unique($roots))));
        sort($unlabelled);

        return $unlabelled;
    }

    /** @return list<class-string> every class under src/ declaring `#[ParticleOp(resource: 'beam-config')]`, sorted */
    private function configOps(): array
    {
        $ops = [];

        foreach ((new AttributedClassScanner)->scan([__DIR__.'/../../src'], ParticleOp::class) as $class) {
            foreach ((new ReflectionClass($class))->getAttributes(ParticleOp::class) as $attribute) {
                if ($attribute->newInstance()->resource === 'beam-config') {
                    $ops[] = $class;
                }
            }
        }

        $ops = array_values(array_unique($ops));
        sort($ops);

        return $ops;
    }
}
