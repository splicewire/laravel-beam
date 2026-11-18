<?php

namespace Splicewire\Beam\Tests\Graph;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Graph\GraphOperations;
use Splicewire\Beam\Tests\Graph\Fixtures\UndescribedGraphInput;
use Splicewire\Beam\Tests\TestCase;

/**
 * **Every property of a derived graph operation's `input:` carries a #[Description].**
 *
 * The inputs publish to a host's API reference and generated SDK as query parameters. A host's own input
 * coverage test (the flagship's ParticleOperationInputCoverageTest, deliberately allowlist-free) fails on
 * any undescribed property, and it first did so only when a host resource gained a `graph:` slot. So the
 * check lives here too, and the next omission shows in beam rather than at a host.
 */
class GraphInputDescriptionTest extends TestCase
{
    /**
     * The constructor properties of `$class` with no non-empty #[Description].
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function undescribed(string $class): array
    {
        $missing = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attributes = $parameter->getAttributes(Description::class);
            $text = $attributes === [] ? '' : trim((string) ($attributes[0]->getArguments()[0] ?? ''));

            if ($text === '') {
                $missing[] = $parameter->getName();
            }
        }

        return $missing;
    }

    #[Test]
    public function test_every_derived_graph_input_property_is_described(): void
    {
        $offences = [];

        foreach (GraphOperations::operationsFor('probe') as $operation) {
            $this->assertIsString($operation->input, $operation->key());

            foreach ($this->undescribed($operation->input) as $property) {
                $offences[] = sprintf('%s %s (%s)', $operation->key(), $property, class_basename($operation->input));
            }
        }

        $this->assertSame([], $offences, "Graph input properties with no #[Description]:\n  - ".implode("\n  - ", $offences));
    }

    /** The check is seen to go red: a fixture input with one described and one bare property. */
    #[Test]
    public function test_the_check_names_an_undescribed_property(): void
    {
        $this->assertSame(['limit'], $this->undescribed(UndescribedGraphInput::class));
    }
}
