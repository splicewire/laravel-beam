<?php

namespace Splicewire\Beam\Config\Generator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Splicewire\Beam\Doctor\Support\ConfigKeyScanner;
use Throwable;

/**
 * The scalar literals (string, int, float, bool; their PHP types kept) each key of one config root is compared against in
 * source (config-sections ticket 06; ints and floats added on review-r1's finding, 2026-11-16), so the
 * generator can emit an `enum` for a key the code treats as a closed vocabulary:
 *
 *     config('beam.ux.head.publisher') === 'Person'            // also !==, ==, !=, either side
 *     in_array(config('beam.ux.head.publisher'), ['A', 'B'])
 *     match (config('beam.ux.head.publisher')) { 'A', 'B' => … }
 *
 * Only a literal key read in the comparison itself counts; a value carried through a variable is not followed, so a
 * key the scan misses simply gets no enum.
 */
class ConfigLiteralComparisons
{
    /**
     * @param  list<string>  $directories  source roots to scan (a package's `src/`, a host's `app/`)
     * @return array<string, list<string|int|float|bool>> key path (dot form) => the literals, typed and sorted
     */
    public static function in(string $root, array $directories): array
    {
        $found = [];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $directories = array_values(array_filter($directories, 'is_dir'));

        foreach (ConfigKeyScanner::filesIn($directories) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, $root)) {
                continue;
            }

            try {
                $statements = $parser->parse($source) ?? [];
            } catch (Throwable) {
                continue;
            }

            foreach ($finder->find($statements, fn (Node $node): bool => $node instanceof BinaryOp || $node instanceof FuncCall || $node instanceof Match_) as $node) {
                foreach (self::comparisons($node) as [$key, $literals]) {
                    if ($key === null || ! str_starts_with($key, $root.'.')) {
                        continue;
                    }

                    $relative = substr($key, strlen($root) + 1);

                    foreach ($literals as $literal) {
                        // Strict, type-keeping dedupe: 3 and '3' are different literals.
                        if (! in_array($literal, $found[$relative] ?? [], true)) {
                            $found[$relative][] = $literal;
                        }
                    }
                }
            }
        }

        foreach ($found as &$literals) {
            usort($literals, fn (string|int|float|bool $a, string|int|float|bool $b): int => [gettype($a), $a] <=> [gettype($b), $b]);
        }

        return $found;
    }

    /** @return list<array{0: ?string, 1: list<string|int|float|bool>}> */
    private static function comparisons(Node $node): array
    {
        if ($node instanceof BinaryOp\Identical || $node instanceof BinaryOp\NotIdentical || $node instanceof BinaryOp\Equal || $node instanceof BinaryOp\NotEqual) {
            [$right, $rightValue] = self::literal($node->right);
            [$left, $leftValue] = self::literal($node->left);

            return match (true) {
                $right => [[self::configKey($node->left), [$rightValue]]],
                $left => [[self::configKey($node->right), [$leftValue]]],
                default => [],
            };
        }

        if ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'in_array') {
            $needle = $node->args[0] ?? null;
            $haystack = $node->args[1] ?? null;

            if ($needle instanceof Node\Arg && $haystack instanceof Node\Arg && $haystack->value instanceof Expr\Array_) {
                $values = [];

                foreach ($haystack->value->items as $item) {
                    [$is, $value] = self::literal($item->value);

                    if ($is) {
                        $values[] = $value;
                    }
                }

                return [[self::configKey($needle->value), $values]];
            }
        }

        if ($node instanceof Match_) {
            $literals = [];

            foreach ($node->arms as $arm) {
                foreach ($arm->conds ?? [] as $cond) {
                    [$is, $value] = self::literal($cond);

                    if ($is) {
                        $literals[] = $value;
                    }
                }
            }

            return [[self::configKey($node->cond), $literals]];
        }

        return [];
    }

    /**
     * Whether an expression is a scalar literal, and its value with its PHP type kept: a string, an int, a float (either
     * negated), or `true`/`false`.
     *
     * @return array{0: bool, 1: string|int|float|bool|null}
     */
    private static function literal(Expr $expr): array
    {
        return match (true) {
            $expr instanceof String_ => [true, $expr->value],
            $expr instanceof Int_, $expr instanceof Float_ => [true, $expr->value],
            $expr instanceof Expr\UnaryMinus && ($expr->expr instanceof Int_ || $expr->expr instanceof Float_) => [true, -$expr->expr->value],
            $expr instanceof Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['true', 'false'], true) => [true, strtolower($expr->name->toString()) === 'true'],
            default => [false, null],
        };
    }

    /** The literal key a `config('k')`, `config()->get('k')` or `Config::get('k')` read names, else null. */
    private static function configKey(Expr $expr): ?string
    {
        $args = match (true) {
            $expr instanceof FuncCall && $expr->name instanceof Name && strtolower($expr->name->toString()) === 'config' => $expr->args,
            $expr instanceof MethodCall && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'get'
                && $expr->var instanceof FuncCall && $expr->var->name instanceof Name && strtolower($expr->var->name->toString()) === 'config' => $expr->args,
            $expr instanceof StaticCall && $expr->class instanceof Name && in_array(strtolower($expr->class->getLast()), ['config'], true)
                && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'get' => $expr->args,
            default => [],
        };

        $first = $args[0] ?? null;

        return $first instanceof Node\Arg && $first->value instanceof String_ ? $first->value->value : null;
    }
}
