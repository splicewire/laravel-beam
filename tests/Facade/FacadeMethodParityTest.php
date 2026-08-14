<?php

namespace Splicewire\Beam\Tests\Facade;

use Illuminate\Support\Traits\Macroable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Splicewire\Beam\BeamManager;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The guard on the facade's hand-written `@method` block (beam-facade ticket 05 §6).
 *
 * Four methods do not justify wiring beam's codegen at the block, but an UNGUARDED block drifts — and
 * static analysis and editor completion across 16 consuming repos are the only thing it is for. So it is
 * hand-written and asserted here instead.
 *
 * Two claims:
 *
 *  1. the tags match {@see BeamManager}'s public methods EXACTLY — no tag for a method that does not exist,
 *     no method without a tag. Ticket 12 makes this a TOTAL statement rather than a lower bound: with no
 *     `Macroable` and no other runtime extension point, the declared methods are the whole surface, so
 *     reflection over them is complete;
 *  2. `write()`'s tag matches {@see ParticleWriter::write()}'s REFLECTED signature. The instance forwards
 *     variadically precisely so the real signature lives in one place; the tag is the one copy of it that
 *     readers see, so it is the one copy that can drift.
 *
 * No container needed — this is pure reflection over two class files, so it extends PHPUnit's TestCase
 * directly rather than Testbench's.
 */
class FacadeMethodParityTest extends TestCase
{
    public function test_the_method_tags_match_the_instances_public_surface(): void
    {
        $tagged = array_keys($this->taggedMethods());

        sort($tagged);

        $this->assertSame(
            $this->publicMethodNames(),
            $tagged,
            'the facade @method block has drifted from the Beam instance\'s public methods',
        );
    }

    public function test_the_surface_is_the_four_methods_ticket_04_locked(): void
    {
        $this->assertSame(
            ['table', 'tableFor', 'tablePrefix', 'write'],
            $this->publicMethodNames(),
            'the surface is CLOSED at ticket 04\'s four methods — adding one is a decision, not a refactor',
        );
    }

    /**
     * The instance's public methods, `__construct` aside, sorted.
     *
     * @return list<string>
     */
    private function publicMethodNames(): array
    {
        $names = array_values(array_filter(
            array_map(
                fn (ReflectionMethod $m) => $m->getName(),
                (new ReflectionClass(BeamManager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            fn (string $name) => $name !== '__construct',
        ));
        sort($names);

        return $names;
    }

    public function test_the_instance_declares_no_runtime_extension_point(): void
    {
        $traits = class_uses_recursive(BeamManager::class);

        $this->assertNotContains(
            Macroable::class,
            $traits,
            'the surface is closed (ticket 12): a sibling extends Beam by registering into a core-owned registry',
        );
    }

    public function test_the_write_tag_matches_the_particle_writers_reflected_signature(): void
    {
        $tag = $this->taggedMethods()['write'] ?? null;
        $this->assertNotNull($tag, 'the facade must carry a @method tag for write()');

        $method = new ReflectionMethod(ParticleWriter::class, 'write');

        $this->assertSame(
            $this->normalize($this->reflectedSignature($method)),
            $this->normalize($tag),
            'the facade\'s @method write(...) tag has drifted from ParticleWriter::write()',
        );
    }

    /**
     * The facade's `@method static <return> <name>(<params>)` tags, keyed by method name, each value being
     * the `<return> <name>(<params>)` remainder.
     *
     * @return array<string, string>
     */
    private function taggedMethods(): array
    {
        $doc = (new ReflectionClass(Beam::class))->getDocComment();
        $this->assertIsString($doc, 'the facade must carry a class docblock with its @method block');

        preg_match_all('/@method\s+static\s+(\S+)\s+(\w+)\((.*)\)/', $doc, $matches, PREG_SET_ORDER);

        $tags = [];
        foreach ($matches as [, $return, $name, $params]) {
            $tags[$name] = $return.' '.$name.'('.$params.')';
        }

        return $tags;
    }

    /** The same `<return> <name>(<params>)` shape, built from reflection. */
    private function reflectedSignature(ReflectionMethod $method): string
    {
        $params = array_map(function (\ReflectionParameter $p): string {
            $type = $p->getType();
            $rendered = $type ? $this->renderType($type).' ' : '';
            $default = '';
            if ($p->isDefaultValueAvailable()) {
                $default = ' = '.str_replace(["\n", ' '], '', var_export($p->getDefaultValue(), true));
            }

            return $rendered.'$'.$p->getName().$default;
        }, $method->getParameters());

        return $this->renderType($method->getReturnType()).' '.$method->getName().'('.implode(', ', $params).')';
    }

    private function renderType(?\ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->isBuiltin() ? $type->getName() : '\\'.$type->getName();

            return ($type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed' ? '?' : '').$name;
        }

        return (string) $type;
    }

    /**
     * Whitespace-, case- and leading-slash-insensitive, so formatting is not what fails this test — only a
     * real change to the name, the parameter list, the order, or a default.
     */
    private function normalize(string $signature): string
    {
        return strtolower(str_replace('\\', '', preg_replace('/\s+/', '', $signature)));
    }
}
