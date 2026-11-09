<?php

namespace Splicewire\Beam\Config;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Reads a config file's STRUCTURE without executing it (ADR-0242): which top-level keys it declares, and which
 * `env()` call feeds each key, by JSON pointer and line.
 *
 * Executing the file would answer neither question honestly. Under `config:cache` the `.env` file is no longer
 * loaded, so a re-evaluated `env()` returns the default; and a value cannot say which env var produced it. The
 * syntax tree can: `'unit_cost_usd' => (float) env('GOOGLE_CSE_QUERY_COST_USD', 0.005)` binds that pointer to that
 * name wherever the call sits inside the value expression.
 *
 * What it cannot see is reported as absence, never guessed: a key built at runtime (a non-literal array key), a
 * value assembled by a function call, or an env var a provider reads directly. A key whose env var the scan
 * cannot see may name it with `#[ConfigKey(env: …)]`.
 */
class ConfigFileScanner
{
    /**
     * @param  list<string>  $within  key path inside the file's returned array to scan from (`['retrieval']` for the
     *                                `splicewire.retrieval` root, which lives as a key of `config/splicewire.php`)
     */
    public function scan(string $file, array $within = []): ConfigFileScan
    {
        $array = $this->returnedArray($file);

        foreach ($within as $segment) {
            $array = $array === null ? null : $this->childArray($array, $segment);
        }

        if ($array === null) {
            return new ConfigFileScan($file, keys: null, bindings: []);
        }

        $keys = [];

        foreach ($array->items as $item) {
            $key = $item->key === null ? null : $this->literalKey($item->key);

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return new ConfigFileScan($file, keys: $keys, bindings: $this->bindings($array, [], $file));
    }

    /**
     * @param  list<string>  $path
     * @return array<string, ConfigEnvBinding>
     */
    private function bindings(Array_ $array, array $path, string $file): array
    {
        $bindings = [];

        foreach ($array->items as $item) {
            if ($item->key === null || ($key = $this->literalKey($item->key)) === null) {
                continue;
            }

            $here = [...$path, $key];

            if ($item->value instanceof Array_) {
                $bindings += $this->bindings($item->value, $here, $file);

                continue;
            }

            $call = $this->envCall($item->value);

            if ($call !== null) {
                $bindings[JsonPointer::from($here)] = $call->withFile($file);
            }
        }

        return $bindings;
    }

    private function envCall(Node $value): ?ConfigEnvBinding
    {
        $call = (new NodeFinder)->findFirst($value, fn (Node $node): bool => $this->envName($node) !== null);

        $name = $call === null ? null : $this->envName($call);

        return $call === null || $name === null ? null : new ConfigEnvBinding(name: $name, file: null, line: $call->getStartLine());
    }

    /** The variable name an `env('NAME', …)` call reads, or null for any other node (or a computed name). */
    private function envName(Node $node): ?string
    {
        if (! $node instanceof FuncCall || ! $node->name instanceof Name || strtolower($node->name->toString()) !== 'env') {
            return null;
        }

        $first = $node->args[0] ?? null;

        return $first instanceof Arg && $first->value instanceof String_ ? $first->value->value : null;
    }

    private function returnedArray(string $file): ?Array_
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (Throwable) {
            return null;
        }

        foreach ($statements as $statement) {
            if ($statement instanceof Return_ && $statement->expr instanceof Array_) {
                return $statement->expr;
            }
        }

        return null;
    }

    private function childArray(Array_ $array, string $key): ?Array_
    {
        foreach ($array->items as $item) {
            if ($item->key !== null && $this->literalKey($item->key) === $key) {
                return $item->value instanceof Array_ ? $item->value : null;
            }
        }

        return null;
    }

    private function literalKey(Node $key): ?string
    {
        return match (true) {
            $key instanceof String_ => $key->value,
            $key instanceof Int_ => (string) $key->value,
            default => null,
        };
    }
}
