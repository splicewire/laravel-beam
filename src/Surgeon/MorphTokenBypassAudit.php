<?php

namespace Splicewire\Beam\Surgeon;

use Composer\Autoload\ClassLoader;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Symfony\Component\Finder\Finder;

/**
 * Find code that hand-assembles a polymorphic `*_type` value instead of asking the morph map.
 *
 * ## The defect this exists for, measured 2026-09-01
 * `tenant_sync_lineages.syncable_type` held **5,871 rows across 18 tenant schemas, 100% fully-qualified
 * class names**. One line did it: `TenantSyncTarget` wrote `'syncable_type' => $class` from a raw
 * class-string parameter, and six readers queried `where('syncable_type', Foo::class)`. Nothing on that
 * path ever called `getMorphClass()`, so the morph map was bypassed entirely.
 *
 * That silently defeated `determination-rename` issue 09 — closed, and aimed at exactly this: *"a package
 * declares a short stable morph key for each of its models, so the database stores that key instead of a
 * fully-qualified class name. A namespace rename then touches zero rows, because no row ever held a class
 * name."* The aliases WERE declared. The rows went on holding class names, because the declaration had no
 * consumer on that path.
 *
 * ## Why {@see MorphAliasCoverageAudit} cannot see this, which is the whole reason for a second audit
 * That audit asks *"does this model have an alias?"* and reports the alias as present — **which it is**.
 * It has no view of whether any writer ASKS for it. The two are complementary: one checks the
 * declaration exists, this one checks the callers use it. Neither substitutes for the other, and the
 * instrument that actually caught the 5,871 rows was a census of stored values, which is a third thing
 * again and cannot run in a package suite.
 *
 * ## Two checks, and the weaker one is the one that matters
 * - `morph-token.class-literal` — a `::class` reaching a `*_type` column. Unambiguously wrong: a
 *   class-string is not a morph token.
 * - `morph-token.raw-value` — a bare variable reaching one. **Ambiguous by construction** — that
 *   variable may already hold a morph key — so it is a nomination, not a proof, and says so. It is
 *   listed anyway because the defect that prompted this audit was exactly this shape, and an audit that
 *   only caught `::class` would have reported the estate clean while 5,871 wrong rows sat there.
 *
 * Correct callers are excluded by construction: a value mentioning `getMorphClass()` or `morphKeyFor()`
 * is the fix, not the defect.
 *
 * ## Advisory, permanently
 * Reach is `vendor/composer/installed.json` (see {@see FamilyPackageSource}) plus the host's own `app/`,
 * so the population is WHAT THIS HOST COMPOSES — a host fact, which by the estate's standing rule is a
 * finding and never a throw. A host that wants it to block registers it in its own manifest with
 * `gate: true` and runs `--floor=warn`.
 */
class MorphTokenBypassAudit implements DoctorAudit
{
    /**
     * A value expression containing any of these is asking the morph map correctly, not bypassing it.
     */
    protected const CORRECT_CALLS = ['getMorphClass', 'morphKeyFor', 'getActualClassNameForMorph'];

    /** @return list<Finding> */
    public function run(): array
    {
        $findings = [];
        $scanned = 0;

        foreach ($this->scanRoots() as $label => $dirs) {
            foreach ($dirs as $dir) {
                if (! is_dir($dir)) {
                    continue;
                }

                foreach ($this->phpFilesIn($dir) as $path => $source) {
                    $scanned++;

                    try {
                        $hits = $this->hitsIn($source);
                    } catch (Error $error) {
                        $findings[] = Finding::inconclusive('morph-token.bypass',
                            $this->relative($path).': source could not be parsed: '.$error->getMessage());

                        continue;
                    }

                    foreach ($hits as $hit) {
                        $findings[] = Finding::warn($hit['check'], sprintf(
                            '%s: %s passes %s into the `%s` column. %s',
                            $label,
                            $this->relative($path),
                            $hit['value'],
                            $hit['column'],
                            $hit['check'] === 'morph-token.class-literal'
                                ? 'A class-string is not a morph token — the map is being bypassed, so the '
                                    .'FQCN lands in the row and a rename breaks every one of them. Ask for it: '
                                    .'`(new Foo)->getMorphClass()`, or a model-side helper that does.'
                                : 'If that variable holds a class-string rather than a morph key, the FQCN '
                                    .'lands in the row. Ambiguous from source alone — confirm it, or route it '
                                    .'through `getMorphClass()` so it cannot be either.'
                        ));
                    }
                }
            }
        }

        // ⚠️ An empty population is "nothing here", not "measured clean" — the trap DoctorAudit's own
        // docblock names. This audit's population is `vendor/composer/installed.json` plus the host's
        // `app/`, so run from a package testbench it scans NOTHING and would otherwise report a
        // confident Pass over zero files. Say so instead.
        if ($scanned === 0) {
            return [Finding::inconclusive(
                'morph-token.bypass',
                'No source was scanned: this host composes no family packages and has no app/ directory, '
                .'so there was no population to measure. Run this from a composed host.'
            )];
        }

        if ($findings === []) {
            return [Finding::pass(
                'morph-token.bypass',
                sprintf('No morph-token bypass candidate found in recognized persistence/query expressions across %d file(s) of family '
                    .'source and host app code; dynamic calls and interprocedural payloads are not covered.', $scanned)
            )];
        }

        return $findings;
    }

    /**
     * @return list<array{check: string, column: string, value: string}>
     */
    public function hitsIn(string $source): array
    {
        // Parse source, never execute it. Comments, strings and cast declarations are not writes.
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse(
            str_contains($source, '<?php') ? $source : "<?php\n".$source
        ) ?? [];
        $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes);
        $finder = new NodeFinder;
        $printer = new Standard;
        $out = [];

        // Follow payload variables conservatively: every assignment is a possible source. This is
        // not control-flow analysis, so ambiguity remains a nomination rather than a clean bill.
        $assignments = [];
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                $assignments[$assignment->var->name][] = $assignment->expr;
            }
        }

        foreach ($finder->find($nodes, fn (Node $node) => $node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\StaticCall) as $call) {
            if ($call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = strtolower($call->name->name);
            $pairs = [];
            if (str_starts_with($method, 'where') || str_starts_with($method, 'orwhere')) {
                $args = $call->getArgs();
                if (isset($args[1]) && $args[0]->value instanceof Node\Scalar\String_) {
                    $pairs[] = [$args[0]->value->value, ($args[2] ?? $args[1])->value];
                }
            }

            // Include query arrays and both identity/update payloads, including indirect payloads.
            if (in_array($method, ['create', 'forcecreate', 'insert', 'insertgetid', 'insertorignore',
                'update', 'upsert', 'updateorcreate', 'updateorinsert', 'firstorcreate', 'firstornew',
                'fill', 'forcefill', 'createmany', 'where', 'orwhere'], true)) {
                foreach ($call->getArgs() as $arg) {
                    array_push($pairs, ...$this->arrayPairs($arg->value, $assignments));
                }
            }

            foreach ($pairs as [$column, $expression]) {
                if (! str_ends_with($column, '_type')) {
                    continue;
                }
                $value = $printer->prettyPrintExpr($expression);
                if ($this->isCorrect($value) || $this->isLiteralToken($value)
                    || $this->isBackedEnumValue($expression, $nodes)) {
                    continue;
                }
                if (str_contains($value, '::class')) {
                    $out[] = ['check' => 'morph-token.class-literal', 'column' => $column, 'value' => $value];
                } elseif (preg_match('/^\$[A-Za-z_]\w*(->\w+)*$/', $value)) {
                    $out[] = ['check' => 'morph-token.raw-value', 'column' => $column, 'value' => $value];
                }
            }
        }

        return $out;
    }

    /** @return list<array{string, Node\Expr}> */
    protected function arrayPairs(Node\Expr $expression, array $assignments, array $seen = []): array
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            if (isset($seen[$expression->name])) {
                return [];
            }
            $seen[$expression->name] = true;
            $pairs = [];
            foreach ($assignments[$expression->name] ?? [] as $value) {
                array_push($pairs, ...$this->arrayPairs($value, $assignments, $seen));
            }

            return $pairs;
        }
        if (! $expression instanceof Node\Expr\Array_) {
            return [];
        }
        $pairs = [];
        foreach ($expression->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->key instanceof Node\Scalar\String_) {
                $pairs[] = [$item->key->value, $item->value];
            } else {
                array_push($pairs, ...$this->arrayPairs($item->value, $assignments, $seen));
            }
        }

        return $pairs;
    }

    protected function isBackedEnumValue(Node\Expr $expression, array $nodes): bool
    {
        if (! $expression instanceof Node\Expr\PropertyFetch
            || ! $expression->name instanceof Node\Identifier || $expression->name->name !== 'value'
            || ! $expression->var instanceof Node\Expr\Variable) {
            return false;
        }
        $scope = $expression;
        while ($scope = $scope->getAttribute('parent')) {
            if ($scope instanceof Node\FunctionLike) {
                foreach ($scope->getParams() as $param) {
                    if ($param->var->name === $expression->var->name && $param->type instanceof Node\Name) {
                        $reassigned = (new NodeFinder)->findFirst($scope->getStmts() ?? [],
                            fn (Node $node) => $node instanceof Node\Expr\Assign
                                && $node->var instanceof Node\Expr\Variable
                                && $node->var->name === $param->var->name);

                        return $reassigned === null && $this->isBackedEnum($param->type->toString(), $nodes);
                    }
                }

                return false;
            }
        }

        return false;
    }

    protected function isBackedEnum(string $name, array $nodes): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Enum_::class) as $enum) {
            if (isset($enum->namespacedName) && $enum->namespacedName->toString() === $name) {
                return $enum->scalarType !== null;
            }
        }
        // Composer can locate an imported enum without autoloading (executing) scanned PHP.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if ($file = $loader->findFile($name)) {
                $declarations = (new ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($file)) ?? [];
                $declarations = (new NodeTraverser(new NameResolver))->traverse($declarations);
                foreach ((new NodeFinder)->findInstanceOf($declarations, Node\Stmt\Enum_::class) as $enum) {
                    if (isset($enum->namespacedName) && $enum->namespacedName->toString() === $name) {
                        return $enum->scalarType !== null;
                    }
                }
            }
        }

        return false;
    }

    protected function isCorrect(string $value): bool
    {
        foreach (static::CORRECT_CALLS as $call) {
            if (str_contains($value, $call)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A quoted string is a morph key spelled inline. Not this audit's finding: it is already a token,
     * not a class. (It is coupled to the alias never changing, which is a style point, not a defect —
     * and flagging it would bury the two checks that are.)
     */
    protected function isLiteralToken(string $value): bool
    {
        return (bool) preg_match('/^[\'"]/', $value);
    }

    /** @return array<string, list<string>> */
    protected function scanRoots(): array
    {
        $roots = (new FamilyPackageSource)->dirs();

        // The host's own code can hand-assemble a morph value exactly as a package can, and nothing else
        // audits it — the flagship is where `PermissionsSeeder` and the app models live.
        if (is_dir($app = base_path('app'))) {
            $roots['(host app)'] = [$app];
        }

        return $roots;
    }

    /** @return array<string, string> path => source */
    protected function phpFilesIn(string $dir): array
    {
        $out = [];

        foreach ((new Finder)->files()->in($dir)->name('*.php') as $file) {
            $out[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
        }

        return $out;
    }

    protected function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
