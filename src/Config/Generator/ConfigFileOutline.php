<?php

namespace Splicewire\Beam\Config\Generator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Splicewire\Beam\Config\JsonPointer;
use Throwable;

/**
 * What a config file's syntax tree says about each key, by JSON pointer (config-sections ticket 06): the value
 * expression's PROVEN type, the class a `Foo::class` value names, the `env()` variable feeding it, and the comment
 * written above it. The file is never executed; {@see \Splicewire\Beam\Config\ConfigFileScanner} reads the same tree
 * for the ladder, and this reads it for the generator.
 *
 * A type is proven only by syntax that fixes it: a cast (`(bool) env(…)`), or a literal. A bare `env('X', false)` is
 * NOT a boolean, because `env()` returns the variable's string when it is set to anything but `true`/`false`; it is
 * `bool|string`.
 */
class ConfigFileOutline
{
    /** @var array<string, array{type: ?string, class: ?string, env: ?string, comment: string, line: int, array: bool, literal: mixed}> */
    private array $keys = [];

    /**
     * @param  list<string>  $within  key path inside the returned array (`['retrieval']` for a root that is a key of its file)
     */
    public static function of(string $file, array $within = []): self
    {
        $outline = new self;
        $array = self::returnedArray($file);

        foreach ($within as $segment) {
            $array = $array === null ? null : self::child($array, $segment);
        }

        if ($array !== null) {
            $outline->walk($array, []);
        }

        return $outline;
    }

    /** @return array{type: ?string, class: ?string, env: ?string, comment: string, line: int, array: bool, literal: mixed}|null */
    public function at(string $pointer): ?array
    {
        return $this->keys[$pointer] ?? null;
    }

    /** @param list<string> $path */
    private function walk(Array_ $array, array $path): void
    {
        foreach ($array->items as $item) {
            $key = $item->key === null ? null : self::literalKey($item->key);

            if ($key === null) {
                continue;
            }

            $here = [...$path, $key];
            $value = $item->value;
            [$type, $class, $env, $literal] = self::typeOf($value);

            $this->keys[JsonPointer::from($here)] = [
                'type' => $type,
                'class' => $class,
                'env' => $env,
                'comment' => self::comment($item),
                'line' => $item->getStartLine(),
                'array' => $value instanceof Array_,
                'literal' => $literal,
            ];

            if ($value instanceof Array_) {
                $this->walk($value, $here);
            }
        }
    }

    /**
     * The proven PHP type of a value expression, the class a `::class` names, the env variable read, and the literal
     * value when the expression is one (a string, int, float or bool), else null.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: mixed}
     */
    private static function typeOf(Expr $value): array
    {
        return match (true) {
            // An array's own keys carry their env bindings; the array itself reads none.
            $value instanceof Array_ => [null, null, null, null],
            $value instanceof Cast\Bool_ => ['bool', null, self::envIn($value), null],
            $value instanceof Cast\Int_ => ['int', null, self::envIn($value), null],
            $value instanceof Cast\Double => ['float', null, self::envIn($value), null],
            $value instanceof Cast\String_ => ['string', null, self::envIn($value), null],
            $value instanceof Int_ => ['int', null, null, $value->value],
            $value instanceof Float_ => ['float', null, null, $value->value],
            $value instanceof String_ => ['string', null, null, $value->value],
            $value instanceof ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false'], true) => ['bool', null, null, strtolower($value->name->toString()) === 'true'],
            $value instanceof ConstFetch && strtolower($value->name->toString()) === 'null' => ['null', null, null, null],
            $value instanceof ClassConstFetch && $value->class instanceof Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class' => ['string', ltrim($value->class->toString(), '\\'), null, null],
            self::isEnv($value) => self::envType($value),
            default => [null, null, self::envIn($value), null],
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: mixed} */
    private static function envType(FuncCall $call): array
    {
        $name = self::envName($call);
        $default = $call->args[1] ?? null;

        if (! $default instanceof Node\Arg) {
            return ['?string', null, $name, null];
        }

        [$type, $class] = self::typeOf($default->value);

        // env() returns the variable's string unless it is one of Laravel's magic words, so a non-string default only
        // widens the type; a null default makes it nullable.
        $widened = match ($type) {
            'string' => 'string',
            'null' => '?string',
            'bool', 'int', 'float' => $type.'|string',
            default => null,
        };

        return [$widened, $class, $name, null];
    }

    /** @phpstan-assert-if-true FuncCall $value */
    private static function isEnv(Expr $value): bool
    {
        return $value instanceof FuncCall && $value->name instanceof Name && strtolower($value->name->toString()) === 'env';
    }

    private static function envIn(Expr $value): ?string
    {
        foreach ((new \PhpParser\NodeFinder)->find($value, fn (Node $node): bool => $node instanceof Expr && self::isEnv($node)) as $call) {
            if ($call instanceof FuncCall && ($name = self::envName($call)) !== null) {
                return $name;
            }
        }

        return null;
    }

    private static function envName(FuncCall $call): ?string
    {
        $first = $call->args[0] ?? null;

        return $first instanceof Node\Arg && $first->value instanceof String_ ? $first->value->value : null;
    }

    /** The comment block directly above an item, with comment syntax and banner rules stripped. */
    private static function comment(Node $item): string
    {
        $lines = [];

        foreach ($item->getComments() as $comment) {
            foreach (preg_split('/\R/', $comment->getText()) ?: [] as $line) {
                $line = trim($line);
                $line = (string) preg_replace('#^(/\*+|\*+/|\*+|//+|\#+|\|)#', '', $line);
                $line = trim((string) preg_replace('#\*+/$#', '', $line));

                if ($line === '' || preg_match('/^[-=|*]{3,}$/', $line) === 1) {
                    $lines[] = '';

                    continue;
                }

                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    private static function returnedArray(string $file): ?Array_
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (Throwable) {
            return null;
        }

        $traverser = new NodeTraverser(new NameResolver);
        $statements = $traverser->traverse($statements);

        foreach ($statements as $statement) {
            if ($statement instanceof Return_ && $statement->expr instanceof Array_) {
                return $statement->expr;
            }
        }

        return null;
    }

    private static function child(Array_ $array, string $key): ?Array_
    {
        foreach ($array->items as $item) {
            if ($item->key !== null && self::literalKey($item->key) === $key) {
                return $item->value instanceof Array_ ? $item->value : null;
            }
        }

        return null;
    }

    private static function literalKey(Node $key): ?string
    {
        return match (true) {
            $key instanceof String_ => $key->value,
            $key instanceof Int_ => (string) $key->value,
            default => null,
        };
    }
}
