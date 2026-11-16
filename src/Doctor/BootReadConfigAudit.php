<?php

namespace Splicewire\Beam\Doctor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Config\ConfigLiveKeys;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Throwable;

/**
 * **A key declared `live` must not be read while the application boots** (config-sections ticket 06). A provider that
 * reads a value in `register`, `boot`, `packageBooted` or `booted` holds it for the life of the process (an Octane
 * worker, a queue worker), so an operator override of that key never reaches the code that uses it, whatever its
 * declaration says.
 *
 * This is the STATIC half: it parses each authored provider (the host's and every overlay-composed family package's,
 * {@see FacadeConformanceScope}) and lists the `config()` reads inside those four methods, closures included:
 * `config('k')`, `config()->get('k')`, `Config::get('k')`, `$app['config']->get('k')` and `$app['config']['k']`, and the
 * whole-repository reads `config()->all()`, `Config::all()`, `$app['config']->all()` and a key-less `get()`.
 *
 * - A LITERAL key that touches a live key (the key, a key below it, or an ancestor) is a finding with its file and line;
 *   a whole-repository read touches every live key.
 * - A DYNAMIC key (built at runtime) is reported as dynamic: the scan cannot say which key it is.
 *
 * {@see \Splicewire\Beam\Testing\BootReadProbe} is the authority: it records the reads that actually happen. Report-only
 * (advisory); ticket 13 turns it into a gate.
 */
class BootReadConfigAudit implements DoctorAudit
{
    public const CHECK = 'config.boot-read';

    private const METHODS = ['register', 'boot', 'packagebooted', 'booted'];

    /**
     * @param  list<class-string>|null  $providers  the providers to scan; null scans the loaded providers inside the authored scope
     */
    public function __construct(
        protected ConfigSectionRegistry $sections,
        protected ?array $providers = null,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $live = ConfigLiveKeys::in($this->sections);
        $literal = [];
        $dynamic = [];

        foreach ($this->providerFiles() as $class => $file) {
            foreach ($this->reads($file) as [$key, $line, $method]) {
                $site = sprintf('%s::%s (%s:%d)', $class, $method, $this->display($file), $line);

                if ($key === null) {
                    $dynamic[] = $site;

                    continue;
                }

                foreach (ConfigLiveKeys::touchedBy($key, $live) as $touched) {
                    $literal[] = Finding::warn(self::CHECK, sprintf(
                        '%s is declared live, but %s is read during boot in %s, so the value is held for the life of the '.
                        'process and an operator override never reaches it. Read it where it is used, or declare it deploy or reload.',
                        $touched,
                        $key === '' ? 'the whole configuration (all() / get(null))' : "`{$key}`",
                        $site,
                    ));
                }
            }
        }

        $findings = $literal;

        if ($dynamic !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d config read(s) during boot use a dynamic key the static scan cannot resolve; the boot-read probe is the '.
                'authority for these: %s',
                count($dynamic),
                implode(', ', array_slice($dynamic, 0, 5)).(count($dynamic) > 5 ? sprintf(' (+%d more)', count($dynamic) - 5) : ''),
            ));
        }

        return $findings !== [] ? $findings : [Finding::pass(self::CHECK, sprintf('No provider reads a live key during boot (%d live key(s) declared).', count($live)))];
    }

    /**
     * Every config read inside a provider's boot-time methods: the literal key (null when dynamic), its line and method.
     *
     * @return list<array{0: ?string, 1: int, 2: string}>
     */
    public function reads(string $file): array
    {
        try {
            $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
            $statements = (new NodeTraverser(new NameResolver))->traverse($statements);
        } catch (Throwable) {
            return [];
        }

        $finder = new NodeFinder;
        $reads = [];

        foreach ($finder->findInstanceOf($statements, Class_::class) as $class) {
            foreach ($class->getMethods() as $method) {
                if (! in_array($method->name->toLowerString(), self::METHODS, true)) {
                    continue;
                }

                foreach ($finder->find($method->stmts ?? [], fn (Node $node): bool => $node instanceof Expr) as $node) {
                    $read = $this->read($node);

                    if ($read !== false) {
                        $reads[] = [$read, $node->getStartLine(), $method->name->toString()];
                    }
                }
            }
        }

        return $reads;
    }

    /**
     * The key a config read names (null when dynamic; `''` for the whole repository), or false when the node is not a
     * config read.
     */
    private function read(Node $node): string|null|false
    {
        if ($this->readsWholeRepository($node)) {
            return '';
        }

        $arg = match (true) {
            $node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'config' => $node->args[0] ?? false,
            $node instanceof MethodCall && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['get', 'has'], true) && $this->isConfig($node->var) => $node->args[0] ?? false,
            $node instanceof StaticCall && $node->class instanceof Name && in_array($node->class->toString(), ['Config', 'Illuminate\\Support\\Facades\\Config'], true)
                && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['get', 'has'], true) => $node->args[0] ?? false,
            $node instanceof ArrayDimFetch && $this->isConfig($node->var) && $node->dim !== null => $node->dim,
            default => false,
        };

        if ($arg === false) {
            return false;
        }

        $value = $arg instanceof Node\Arg ? $arg->value : $arg;

        // `config()` with no argument, or `config([...])` (a write), is not a read of one key.
        if ($node instanceof FuncCall && ($node->args === [] || $value instanceof Expr\Array_)) {
            return false;
        }

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * `config()->all()`, `Config::all()`, `$app['config']->all()`, and a `get()` with no key or a literal null key: each
     * returns the whole repository, a read of every live key in it (review-r1, 2026-11-16).
     */
    private function readsWholeRepository(Node $node): bool
    {
        $method = ($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        $onConfig = ($node instanceof MethodCall && $this->isConfig($node->var))
            || ($node instanceof StaticCall && $node->class instanceof Name && in_array($node->class->toString(), ['Config', 'Illuminate\\Support\\Facades\\Config'], true));

        if (! $onConfig || $method === null) {
            return false;
        }

        if ($method === 'all') {
            return true;
        }

        $first = $node->args[0] ?? null;

        return $method === 'get' && ($first === null || ($first instanceof Node\Arg && $first->value instanceof Expr\ConstFetch && strtolower($first->value->name->toString()) === 'null'));
    }

    /** `config()`, `$app['config']`, `$this->app['config']`, `app('config')`. */
    private function isConfig(Node $node): bool
    {
        return ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'config' && $node->args === [])
            || ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'app'
                && ($node->args[0] ?? null) instanceof Node\Arg && $node->args[0]->value instanceof String_ && $node->args[0]->value->value === 'config')
            || ($node instanceof ArrayDimFetch && $node->dim instanceof String_ && $node->dim->value === 'config');
    }

    /** @return array<class-string, string> provider class => file */
    protected function providerFiles(): array
    {
        $files = [];
        $scope = $this->providers === null ? $this->authoredRoots() : null;

        foreach ($this->providers ?? array_keys(app()->getLoadedProviders()) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $file = (new ReflectionClass($class))->getFileName();

            if ($file === false) {
                continue;
            }

            $file = (string) realpath($file);

            if ($scope !== null && array_filter($scope, fn (string $root): bool => str_starts_with($file, $root.'/')) === []) {
                continue;
            }

            $files[$class] = $file;
        }

        return $files;
    }

    /** @return list<string> */
    protected function authoredRoots(): array
    {
        return array_values(array_map(fn (string $dir): string => (string) realpath($dir), array_filter([
            base_path('app'),
            base_path('src'),
            ...FacadeConformanceScope::authorablePackageRoots(),
        ], 'is_dir')));
    }

    private function display(string $file): string
    {
        return str_starts_with($file, base_path().'/') ? substr($file, strlen(base_path()) + 1) : $file;
    }
}
