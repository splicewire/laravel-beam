<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

/**
 * Fixture (config-sections 07, review-r1's nearest-declaration boundary): a Config Section GENERATED from a seed, with
 * random nesting of records, string maps and leaves, each property declared Tenant, Operator or Host or left undeclared
 * (so it takes its nearest ancestor's declaration), and records and maps randomly nullable, with null central values.
 * The classes are defined with eval(), once per seed, and the tree's central value is generated alongside them.
 *
 * Deterministic for a seed: it draws from its own generator, never the global one.
 */
final class GeneratedConfigTree
{
    private const CEILINGS = ['Tenant', 'Operator', 'Host', null];

    /** @var array{kind: string, children?: array<string, mixed>} */
    public array $spec;

    /** @var array<string, mixed> */
    public array $central;

    public string $root;

    /** @var class-string */
    public string $section;

    private int $classes = 0;

    private function __construct(public readonly int $seed, private readonly \Random\Randomizer $random) {}

    public static function make(int $seed): self
    {
        $tree = new self($seed, new \Random\Randomizer(new \Random\Engine\Mt19937($seed)));
        $tree->root = "cs07gen{$seed}";
        $tree->section = "Cs07Gen{$seed}Section";
        [$tree->spec, $tree->central, $source] = $tree->record(0, $tree->section, true);

        if (! class_exists($tree->section, false)) {
            eval($source);
        }

        return $tree;
    }

    /**
     * What the tree declares at `$segments`: `record`, `map`, `entry` (a map's entry), `leaf`, or null (nothing).
     *
     * @param  list<string>  $segments
     */
    public function kind(array $segments): ?string
    {
        $spec = $this->spec;

        foreach ($segments as $segment) {
            if ($spec['kind'] === 'map') {
                $spec = ['kind' => 'entry'];

                continue;
            }

            if ($spec['kind'] !== 'record' || ! isset($spec['children'][$segment])) {
                return null;
            }

            $spec = $spec['children'][$segment];
        }

        return $spec['kind'];
    }

    /**
     * A property's declaration, or null for none. Weighted so most trees give the tenant somewhere to write (an undeclared
     * top-level key is host-owned) and often hold the boundary the tier walk exists for: a refused record between a
     * tenant-owned ancestor and a tenant-owned descendant.
     */
    private function ceiling(int $depth): ?string
    {
        $roll = $this->int(1, 100);

        if ($depth === 0) {
            return $roll <= 65 ? 'Tenant' : self::CEILINGS[$this->int(1, 3)];
        }

        return match (true) {
            $roll <= 35 => 'Tenant',
            $roll <= 60 => 'Operator',
            $roll <= 75 => 'Host',
            default => null,
        };
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string} the spec, the central value, and the PHP source
     */
    private function record(int $depth, string $class, bool $section = false): array
    {
        $children = [];
        $central = [];
        $params = [];
        $source = '';

        for ($i = 0, $n = $this->int(1, 3); $i < $n; $i++) {
            $name = "k{$i}";
            $roll = $this->int(1, 100);
            $kind = $roll <= 40 || $depth >= 3 ? 'leaf' : ($roll <= 75 ? 'record' : 'map');
            $ceiling = $this->ceiling($depth);
            $nullable = $kind !== 'leaf' && $this->chance(35);
            $attributes = $ceiling === null ? [] : ["\\Splicewire\\Beam\\Config\\Attributes\\ConfigKey(ceiling: \\Splicewire\\Beam\\Enums\\ConfigTier::{$ceiling}, effect: \\Splicewire\\Beam\\Enums\\ConfigEffect::Live)"];
            $doc = '';

            if ($kind === 'leaf') {
                $type = 'string';
                $children[$name] = ['kind' => 'leaf'];
                $central[$name] = 'c'.$this->int(100, 999);
            } elseif ($kind === 'record') {
                $child = $class.'R'.(++$this->classes);
                [$children[$name], $value, $childSource] = $this->record($depth + 1, $child);
                $source .= $childSource;
                $type = ($nullable ? '?' : '').$child;
                $central[$name] = $nullable && $this->chance(30) ? null : $value;
            } else {
                $attributes[] = "\\Schemastud\\DataSchemas\\Attributes\\MapValues('string')";
                $doc = '/** @var array<string, string>'.($nullable ? '|null' : '').' */ ';
                $type = ($nullable ? '?' : '').'array';
                $children[$name] = ['kind' => 'map'];
                $entries = [];

                for ($e = 0, $m = $this->int(1, 3); $e < $m; $e++) {
                    $entries["e{$e}"] = 'c'.$this->int(100, 999);
                }

                $central[$name] = $nullable && $this->chance(30) ? null : $entries;
            }

            $params[] = $doc.($attributes === [] ? '' : '#['.implode(', ', $attributes).'] ')."public {$type} \${$name}";
        }

        $head = $section
            ? "#[\\Splicewire\\Beam\\Config\\Attributes\\ConfigRoot(root: '{$this->root}', title: 'Generated {$this->seed}')]\nclass {$class} extends \\Splicewire\\Beam\\Config\\ConfigSection"
            : "class {$class} extends \\Spatie\\LaravelData\\Data";

        return [
            ['kind' => 'record', 'children' => $children],
            $central,
            $source."{$head}\n{\n    public function __construct(\n        ".implode(",\n        ", $params)."\n    ) {}\n}\n",
        ];
    }
}
