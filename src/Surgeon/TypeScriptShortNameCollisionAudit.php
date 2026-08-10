<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Conformance\InstalledPackages;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cross-package `#[TypeScript]` emitted-path collision detector (ticket 31, updated tickets 33/34). This
 * session hit the same failure shape repeatedly: two distinct PHP classes, same short name, different
 * packages, both `#[TypeScript]`-annotated — `ThreadMessageData`, `CreateInvitationData`/
 * `InvitationResourceData`, `MembershipResourceData`, `TokenResourceData`, `PlanData`.
 * `App\Support\TypeScript\HostFacingDataTransformer` has a collision DISCRIMINATOR (pick the annotated
 * twin over an unannotated one) but no collision DETECTOR — it only fails (silent double-emit ->
 * downstream babel parse crash in `SdkHookMigrationAudit`'s bridge) when BOTH twins carry the attribute.
 *
 * TICKET 34 UPDATE: `App\Support\TypeScript\HostFacingDataTransformer` no longer remaps anything —
 * every class emits at its REAL NATIVE namespace (dots for backslashes), which is collision-proof by
 * construction (PHP FQNs are globally unique; two classes can never share one). So this audit's ONLY
 * remaining real failure mode is an explicit `#[TypeScript(name:, location:)]` override — the one way
 * two DIFFERENT classes could still be made to collide, since an override bypasses the natural
 * namespace-derived uniqueness. `emittedPathFor()` honors that override when present, falling back to
 * the plain native path otherwise (which can never collide with anything, by construction, so grouping
 * by it alone would make this audit a tautology without the override handling).
 *
 * Deliberately does NOT import `App\Providers\TypeScriptTransformerServiceProvider` /
 * `App\Support\TypeScript\HostNamespaceProjection` (a `laravel-beam` class must never depend on an
 * app-namespace class, per the estate's declared topology) — uses `rushing/laravel-surgeon`'s
 * `InstalledPackages` (a foundation-tier dependency, safe to depend on) to enumerate installed
 * `splicewire/*` packages dynamically instead of a hand-kept list.
 *
 * Plain {@see DoctorAudit} — no `SuggestsOperations`. Ticket 30's `InvitationResourceData` case proved a
 * matching field shape doesn't mean the two classes are safely interchangeable (genuinely different
 * backing models/business logic) — every collision found this session needed a human/agent to actually
 * read both classes, not a mechanical pick.
 */
class TypeScriptShortNameCollisionAudit implements DoctorAudit
{
    public const CHECK = 'sdk.typescript-short-name-collision';

    protected const PACKAGE_TYPE_DIRS = ['Data', 'Enums', 'Spiders'];

    /**
     * @param  list<array{fqn: string, shortName: string, name: string|null, location: array<int, string>|null}>  $annotatedClasses  Every class/enum
     *                                                                                                                               found under the scanned directories that carries `#[TypeScript]`, with any `name:`/`location:` override it declared.
     */
    public function __construct(protected array $annotatedClasses) {}

    /**
     * The default host wiring: scan every installed `splicewire/*` package's `Data`/`Enums`/`Spiders`
     * pivot dirs (via `InstalledPackages`, not a hand-kept list), plus the app's own equivalents, for
     * every `#[TypeScript]`-annotated class/enum.
     */
    public static function forApp(): self
    {
        $directories = [];
        $installed = InstalledPackages::fromHostRoot(base_path());

        foreach ($installed->namedLike('splicewire/') as $path) {
            foreach (self::PACKAGE_TYPE_DIRS as $typeDir) {
                $dir = rtrim($path, '/')."/src/{$typeDir}";
                if (is_dir($dir)) {
                    $directories[] = $dir;
                }
            }
        }

        foreach (self::PACKAGE_TYPE_DIRS as $typeDir) {
            $path = app_path($typeDir);
            if (is_dir($path)) {
                $directories[] = $path;
            }
        }

        $annotated = [];
        foreach ($directories as $dir) {
            foreach (self::collectAnnotatedClasses($dir) as $row) {
                $annotated[] = $row;
            }
        }

        return new self($annotated);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return $this->check($this->annotatedClasses);
    }

    /**
     * The pure core — a plain list in, no filesystem/reflection. Directly unit-testable.
     *
     * @param  list<array{fqn: string, shortName: string, name?: string|null, location?: array<int, string>|null}>  $annotatedClasses
     * @return list<Finding>
     */
    public function check(array $annotatedClasses): array
    {
        $byEmittedPath = [];
        foreach ($annotatedClasses as $row) {
            $path = self::emittedPathFor($row);
            $byEmittedPath[$path][] = $row['fqn'];
        }

        $findings = [];
        foreach ($byEmittedPath as $emittedPath => $fqns) {
            $unique = array_values(array_unique($fqns));
            if (count($unique) < 2) {
                continue;
            }

            sort($unique);
            $findings[] = Finding::fail(self::CHECK, sprintf(
                "%d #[TypeScript]-annotated classes resolve to the same emitted TS path '%s' and collide: ".
                '%s. Only one can safely emit — read both before picking (a matching field shape does not '.
                'mean the two are safely interchangeable; see ticket 30\'s InvitationResourceData '.
                'resolution). Decide the canonical one and remove #[TypeScript] from the other, or rename '.
                'one.',
                count($unique),
                $emittedPath,
                implode(', ', $unique),
            ));
        }

        return $findings;
    }

    /**
     * The FQCN's real emitted TS path — the native namespace (dots for backslashes) UNLESS the class
     * declared an explicit `#[TypeScript(name:, location:)]` override, mirroring stock
     * `AttributedClassTransformer`'s own override-handling (the only remaining way two different
     * classes could still collide post-ticket-34).
     *
     * @param  array{fqn: string, shortName: string, name?: string|null, location?: array<int, string>|null}  $row
     */
    protected static function emittedPathFor(array $row): string
    {
        $name = $row['name'] ?? null;
        $location = $row['location'] ?? null;

        if ($name === null && $location === null) {
            return str_replace('\\', '.', ltrim($row['fqn'], '\\'));
        }

        $locationPath = $location !== null ? implode('.', $location) : self::namespacePath($row['fqn']);
        $shortName = $name ?? $row['shortName'];

        return $locationPath === '' ? $shortName : $locationPath.'.'.$shortName;
    }

    protected static function namespacePath(string $fqn): string
    {
        $fqn = ltrim($fqn, '\\');
        $pos = strrpos($fqn, '\\');

        return $pos === false ? '' : str_replace('\\', '.', substr($fqn, 0, $pos));
    }

    /**
     * @return list<array{fqn: string, shortName: string, name: string|null, location: array<int, string>|null}>
     */
    protected static function collectAnnotatedClasses(string $dir): array
    {
        $rows = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $fqn = self::extractFqn($source);
            if ($fqn === null) {
                continue;
            }

            if (! class_exists($fqn) && ! enum_exists($fqn) && ! interface_exists($fqn)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($fqn);
            } catch (\Throwable) {
                continue;
            }

            $attribute = $reflection->getAttributes(TypeScript::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }

            $args = $attribute->getArguments();
            $rows[] = [
                'fqn' => $fqn,
                'shortName' => self::shortName($fqn),
                'name' => is_string($args['name'] ?? null) ? $args['name'] : null,
                'location' => is_array($args['location'] ?? null) ? $args['location'] : null,
            ];
        }

        return $rows;
    }

    protected static function extractFqn(string $source): ?string
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder;
        /** @var Namespace_|null $namespace */
        $namespace = $finder->findFirstInstanceOf($ast, Namespace_::class);
        /** @var ClassLike|null $classLike */
        $classLike = $finder->findFirstInstanceOf($ast, ClassLike::class);

        if ($classLike === null || $classLike->name === null) {
            return null;
        }

        $namespaceName = $namespace?->name?->toString();

        return $namespaceName !== null && $namespaceName !== ''
            ? $namespaceName.'\\'.$classLike->name->toString()
            : $classLike->name->toString();
    }

    protected static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
