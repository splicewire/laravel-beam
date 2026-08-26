<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

/**
 * The BARE MOUNT audit (api-surface-coherence ticket 93): a `Route::particle*()` call site is the
 * SECOND spelling of a mount that `Particle::` is now the sanctioned front door for, and this audit
 * turns each one into a deterministic review finding.
 *
 * ## Why an audit and not a delete
 * Ticket 49 shipped `Particle::mount()` and then declined its own instruction to delete the six route
 * macros. Ticket 93 re-measured why, and the reason is not the call-site count — it is that
 * beam-facade ticket 26 ruled the estate resolves family packages **from git by default**, with 16
 * family packages carrying no local source on this machine at all. A hard delete in beam core is
 * therefore a breaking change against a consumer set that cannot be enumerated from any one machine,
 * so enforcement-by-absence cannot be safely bought.
 *
 * An audit can. It reaches the packages you cannot see — they get the finding the moment they next
 * `composer update` — it costs no release coupling, and it is reversible. What it deliberately does
 * NOT do is remove the second spelling; that is the visible-estate sweep (`surgeon:rewrite`), which
 * closes these findings repo by repo at each one's own pace.
 *
 * ## The six macros and what each becomes
 * All six macro bodies moved verbatim into {@see ParticleMounter}, so
 * both spellings already share ONE implementation — this is a coherence finding, never a correctness
 * one, and every mapping below is argument-for-argument identical:
 *
 *   Route::particleResource(…)     →  Particle::mount(…)
 *   Route::particleOp(…)           →  Particle::ops(…)      (`$ops` takes a bare name)
 *   Route::particleOps(…)          →  Particle::ops(…)
 *   Route::particleRelative(…)     →  Particle::relative(…)
 *   Route::resourceRenderings(…)   →  Particle::renderings(…)
 *   Route::resourceFilters(…)      →  Particle::filters(…)
 *
 * Note `particleOp` (singular) is the macro hosts actually still call, and its front door is the
 * PLURAL `Particle::ops()` — there is no `Particle::op()`.
 *
 * The builder is the WRONG target for these sites, though not an impossible one:
 * `mount(…)->only([])->ops(…)` still publishes the automatic filter sub-surface (nine routes at a URI
 * the host only wanted an operation on), because `only` gates the CRUD verbs and `filters` is a
 * separate opt-out. `mount(…)->only([])->filters(false)->ops(…)` DOES emit the same table as
 * `Particle::ops(…)` — it is a footgun, not an impossibility, and naming the op-only shape is cheaper
 * than remembering the second opt-out at every call site. Rewrite to the verb.
 *
 * ## Detection is AST, not grep
 * Findings key on real {@see StaticCall} nodes against a `Route` class name, so the macro
 * DEFINITIONS (`Route::macro('particleOp', …)` — a string argument, not a call), prose in config
 * files, and the ~180 comment/docblock mentions across the estate are all invisible to this pass by
 * construction. Ticket 93 measured 300 textual hits against ~120 executing call sites; the gap is
 * exactly what an AST pass drops and a grep does not.
 *
 * ## Honesty about reach
 * Two limits, both structural:
 *
 * 1. **A macro call reached through a variable or an alias is invisible.** Detection matches
 *    `Route::` (short name or the fully-qualified facade) — `$router->particleOp(…)` on an injected
 *    Router, or a facade aliased to another name, is not seen. Every call site measured in the estate
 *    uses the `Route::` spelling, so this is a known gap rather than an observed one.
 * 2. **The sweep sees what the host composes.** Scan paths are the host's own `routes/` and `app/`
 *    plus whatever packages contributed to {@see AuditScanPaths} from their own providers — a
 *    boot-time seam, so a package not installed in the host under audit contributes nothing there.
 *    `app/` is scanned as well as `routes/` because the estate's real call sites are as often in a
 *    service provider (`Resources.php`, `DomainResourceServiceProvider`) as in a route file.
 */
class BareParticleMountAudit implements DoctorAudit
{
    public const CHECK = 'particle.bare-mount';

    /**
     * The six macro names, each mapped to the `Particle::` verb that replaces it argument-for-argument.
     *
     * @var array<string, string>
     */
    public const MACRO_FRONT_DOORS = [
        'particleResource' => 'mount',
        'particleOp' => 'ops',
        'particleOps' => 'ops',
        'particleRelative' => 'relative',
        'resourceRenderings' => 'renderings',
        'resourceFilters' => 'filters',
    ];

    /** Class names a static call must be against for its method to count as a route macro. */
    public const ROUTE_CLASS_NAMES = [
        'Route',
        'Illuminate\Support\Facades\Route',
    ];

    /** @var list<string> */
    protected array $scanDirs;

    /**
     * @param  string|list<string>  $scanDirs
     */
    public function __construct(string|array $scanDirs)
    {
        $this->scanDirs = array_values((array) $scanDirs);
    }

    /**
     * The default host-scoped wiring — mirrors {@see ParticleOperationBypassAudit::forRoutes()}, folding
     * the package-contributed {@see AuditScanPaths} dirs in ALONGSIDE the host's own. Kept off the
     * constructor so the class is pure-unit testable via {@see findingsFor()} — no disk, no container.
     *
     * @param  string|list<string>|null  $scanDirs
     */
    public static function forRoutes(string|array|null $scanDirs = null): self
    {
        $contributed = app()->bound(AuditScanPaths::class) ? app(AuditScanPaths::class) : null;

        $scanDirs ??= [
            base_path('routes'),
            base_path('app'),
            ...($contributed?->routesDirs() ?? []),
            ...($contributed?->controllersDirs() ?? []),
        ];

        return new self($scanDirs);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return $this->findingsFor($this->collectSites($this->scanDirs));
    }

    /**
     * The pure core — no disk, no parser, no container. Given the located call sites, produce one WARN
     * per site naming the front door that replaces it, or a single PASS when the scan is clean.
     *
     * @param  list<array{macro: string, file: string, line: int}>  $sites
     * @return list<Finding>
     */
    public function findingsFor(array $sites): array
    {
        if ($sites === []) {
            return [Finding::pass(self::CHECK, 'No bare `Route::particle*()` mount call sites — every mount rides the `Particle::` front door.')];
        }

        $findings = [];

        foreach ($sites as $site) {
            $verb = self::MACRO_FRONT_DOORS[$site['macro']] ?? null;
            if ($verb === null) {
                continue;
            }

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s:%d mounts through the `Route::%s()` macro — the second spelling of a mount whose front '
                .'door is `Particle::%s()`. Both call the same ParticleMounter body, so this is a coherence '
                .'finding, not a bug: rewrite the call site argument-for-argument and import '
                .'`Splicewire\Beam\Facades\Particle`.',
                $site['file'],
                $site['line'],
                $site['macro'],
                $verb,
            ));
        }

        return $findings;
    }

    /**
     * Every executing macro call site under the scanned dirs, in file order.
     *
     * @param  list<string>  $dirs
     * @return list<array{macro: string, file: string, line: int}>
     */
    protected function collectSites(array $dirs): array
    {
        $sites = [];

        foreach ($this->phpFilesUnder($dirs) as $file) {
            foreach ($this->sitesIn((string) file_get_contents($file)) as $site) {
                $sites[] = ['macro' => $site['macro'], 'file' => $file, 'line' => $site['line']];
            }
        }

        return $sites;
    }

    /**
     * The AST pass over one file's source: `Route::<macro>(…)` static calls only.
     *
     * @return list<array{macro: string, line: int}>
     */
    public function sitesIn(string $source): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        if ($ast === null) {
            return [];
        }

        $sites = [];

        /** @var list<StaticCall> $calls */
        $calls = (new NodeFinder)->findInstanceOf($ast, StaticCall::class);

        foreach ($calls as $call) {
            if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
                continue;
            }

            if (! in_array(ltrim($call->class->toString(), '\\'), self::ROUTE_CLASS_NAMES, true)) {
                continue;
            }

            if (! isset(self::MACRO_FRONT_DOORS[$call->name->toString()])) {
                continue;
            }

            $sites[] = ['macro' => $call->name->toString(), 'line' => $call->getStartLine()];
        }

        return $sites;
    }

    /**
     * @param  list<string>  $dirs
     * @return list<string>
     */
    protected function phpFilesUnder(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
