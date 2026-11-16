<?php

namespace Splicewire\Beam\Config\Generator;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/**
 * How an EXISTING section differs from what the generator would emit now (config-sections ticket 06). Re-running the
 * generator on a root that already has a section never writes: a section is hand-edited (ceilings, effects, rewritten
 * copy), and regenerating would erase that. It reports the drift instead, by pointer:
 *
 * - a key the config has and the section does not describe (`missing`);
 * - a key the section describes and the config no longer has (`gone`);
 * - a key whose declared type differs from the type the generator infers now (`retyped`).
 *
 * The existing classes are read from their syntax tree, never loaded, so a section whose classes are not autoloadable
 * in this process (a package's own namespace) is compared all the same. A nested class is looked for beside the section
 * file, by its short name.
 */
class ConfigSectionDrift
{
    /** @var array<string, array{props: array<string, string>, maps: array<string, string>, entries: ?string}> */
    private array $existing = [];

    /** @return list<string> one line per difference */
    public function between(InferredSection $inferred, string $sectionFile): array
    {
        $this->existing = $this->read(dirname($sectionFile));
        $section = basename($sectionFile, '.php');

        if (! isset($this->existing[$section])) {
            return ["the existing file declares no class {$section}"];
        }

        $drift = [];
        $entries = $this->existing[$section]['entries'];

        if ($inferred->entries !== null || $entries !== null) {
            if ($inferred->entries === null || $entries === null) {
                return [sprintf('the root is %s in the config but %s in the section', $inferred->entries === null ? 'a record' : 'a map', $entries === null ? 'a record' : 'a map')];
            }

            $this->compare($inferred, $inferred->entries, $entries, '/*', $drift);

            return $drift;
        }

        $this->compare($inferred, $inferred->sectionClass, $section, '', $drift);

        return $drift;
    }

    /** @param list<string> $drift */
    private function compare(InferredSection $inferred, string $inferredClass, string $existingClass, string $pointer, array &$drift): void
    {
        $have = $this->existing[$existingClass]['props'] ?? null;

        if ($have === null) {
            $drift[] = "{$pointer}: the section's class {$existingClass} was not found beside the section file";

            return;
        }

        $want = [];

        foreach ($inferred->classes[$inferredClass] ?? [] as $key) {
            $want[$key->name] = $key;
        }

        foreach ($want as $name => $key) {
            $here = $pointer.'/'.$name;

            if (! array_key_exists($name, $have)) {
                $drift[] = "{$here}: missing (the config has it; the generator would type it {$this->shown($key->type, $key->optional)})";

                continue;
            }

            $nested = isset($inferred->classes[$key->type]);
            $existingNested = isset($this->existing[$have[$name]]);

            if ($nested && $existingNested) {
                $this->compare($inferred, $key->type, $have[$name], $here, $drift);

                continue;
            }

            if ($key->mapValues !== null && isset($inferred->classes[$key->mapValues]) && isset($this->existing[$existingClass]['maps'][$name])) {
                $this->compare($inferred, $key->mapValues, $this->existing[$existingClass]['maps'][$name], $here.'/*', $drift);

                continue;
            }

            $now = $this->normalized($this->shown($key->type, $key->optional));

            if ($now !== $this->normalized($have[$name])) {
                $drift[] = "{$here}: retyped (the section declares {$have[$name]}; the config now infers {$this->shown($key->type, $key->optional)})";
            }
        }

        foreach (array_keys($have) as $name) {
            if (! array_key_exists($name, $want)) {
                $drift[] = "{$pointer}/{$name}: gone (the section describes it; the config no longer has it)";
            }
        }
    }

    private function shown(string $type, bool $optional): string
    {
        return $optional ? ltrim($type, '?').'|Optional'.(str_starts_with($type, '?') ? '|null' : '') : $type;
    }

    private function normalized(string $type): string
    {
        $parts = [];

        foreach (explode('|', $type) as $part) {
            if (str_starts_with($part, '?')) {
                $parts[] = 'null';
                $part = substr($part, 1);
            }

            $parts[] = strtolower(ltrim((string) strrchr('\\'.$part, '\\'), '\\'));
        }

        $parts = array_unique($parts);
        sort($parts);

        return implode('|', $parts);
    }

    /** @return array<string, array{props: array<string, string>, maps: array<string, string>, entries: ?string}> */
    private function read(string $directory): array
    {
        $classes = [];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            try {
                $statements = (new NodeTraverser(new NameResolver))->traverse($parser->parse((string) file_get_contents($file)) ?? []);
            } catch (Throwable) {
                continue;
            }

            foreach ((new NodeFinder)->findInstanceOf($statements, Class_::class) as $class) {
                if ($class->name === null) {
                    continue;
                }

                $props = [];
                $maps = [];

                foreach ($class->getMethod('__construct')->params ?? [] as $param) {
                    if ($param->flags === 0 || ! $param->var instanceof Node\Expr\Variable || ! is_string($param->var->name)) {
                        continue;
                    }

                    $props[$param->var->name] = $this->typeString($param->type);

                    foreach ($param->attrGroups as $group) {
                        foreach ($group->attrs as $attr) {
                            $value = $attr->args[0]->value ?? null;

                            if ($attr->name->getLast() === 'MapValues' && $value instanceof ClassConstFetch && $value->class instanceof Name) {
                                $maps[$param->var->name] = $value->class->getLast();
                            }
                        }
                    }
                }

                $classes[$class->name->toString()] = ['props' => $props, 'maps' => $maps, 'entries' => $this->entriesOf($class)];
            }
        }

        return $classes;
    }

    private function entriesOf(Class_ $class): ?string
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() !== 'ConfigRoot') {
                    continue;
                }

                foreach ($attr->args as $arg) {
                    if ($arg->name?->toString() === 'entries' && $arg->value instanceof ClassConstFetch && $arg->value->class instanceof Name) {
                        return $arg->value->class->getLast();
                    }
                }
            }
        }

        return null;
    }

    private function typeString(?Node $type): string
    {
        return match (true) {
            $type === null => 'mixed',
            $type instanceof NullableType => '?'.$this->typeString($type->type),
            $type instanceof UnionType => implode('|', array_map(fn (Node $part): string => $this->typeString($part), $type->types)),
            $type instanceof Name => $type->getLast(),
            $type instanceof Identifier => $type->toString(),
            default => 'mixed',
        };
    }
}
