<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Source\RouteManifestSource;

/**
 * `->returns()`/`->streams()` ↔ `#[TypeScript]` resolution backstop (ticket 29 item D). Every audit/fix
 * pass across tickets 25/26/28/29 A-C independently re-discovered the same failure shape: a route
 * annotation that LOOKS fine (a plausible class name, sometimes even a real, loadable class) but doesn't
 * actually produce real emitted TypeScript output — a missing `use` import silently resolving `::class`
 * to a bare unqualified string (item A), a class referencing the wrong one of two same-named twins in
 * different namespaces (item B's `AuthUserData`/`TowerAuthUserData`, item C's `ThreadMessageData`
 * rediscovery of the identical pattern), or a genuinely out-of-scan-directory class (item C's
 * `LlmKeySecretData`). None of these is "just add `#[TypeScript]`" — checking for the attribute alone
 * would have caught NONE of the 8+9 real cases those items found by hand. This audit checks the actual
 * END RESULT instead of re-deriving the emission rules (which is exactly the kind of logic that already
 * drifted once — `splicewire/tower`'s `ClientTypeName::PROJECTED_PREFIXES` has to be hand-kept in sync
 * with `HostNamespaceProjection::REMAP_PREFIXES` for the same reason):
 *
 *   1. **Resolvable** — does the raw class-name string on the route's action array
 *      (`getAction('returns')`/`getAction('streams')`, BEFORE any TS-path computation) actually
 *      `class_exists()`? Catches item A's whole bug class directly, no cross-package dependency needed.
 *   2. **Actually emitted** — does the manifest's ALREADY-COMPUTED TS-qualified path (the same
 *      `entry['returns']`/`entry['streams']` values {@see SdkReturnsCoverageAudit::forApp()} reads off
 *      `RouteManifestSource::toArray()`, pre-resolved by tower's `RouteManifest` via `ClientTypeName` —
 *      deliberately NOT recomputed here; a `laravel-beam` class must never import a `splicewire/tower`
 *      class, per the estate's declared beam↓tower topology) correspond to a REAL `interface`/`type`
 *      declaration under the right namespace root in `ui/src/types/generated.d.ts`? Catches items B/C's
 *      whole bug class — a resolvable-but-wrong-or-uncovered class, regardless of why.
 *
 * A route whose class fails check 1 never reaches check 2 (nothing to look up). Building the manifest
 * itself can throw (`ClientTypeName::for()` throws on an unresolvable class) — caught per-realm so one
 * bad route doesn't blind the whole audit to every other route; that realm still gets full check-1
 * coverage via the raw route walk, just skips check 2 for it (there is no computed path to check
 * against).
 *
 * Plain {@see DoctorAudit} — no `SuggestsOperations`. Every case found this session needed a human/agent
 * to actually look at the code (half turned out to be "wrong class entirely," not "missing annotation")
 * — the same reasoning `SdkReturnsCoverageAudit` already applies to its own tiering.
 */
class SdkReturnsTypeScriptResolutionAudit implements DoctorAudit
{
    public const CHECK = 'sdk.returns-typescript-resolution';

    /**
     * @param  list<array{routeName: ?string, uri: string, source: string, field: string, class: string}>  $rawReferences
     *                                                                                                                     Every raw class-name string found on a route's `returns`/`streams` action value, BEFORE any
     *                                                                                                                     resolution — `field` is `'returns'` or `'streams:<eventName>'` for context in the finding message.
     * @param  array<string, array{returns?: string, returnsMany?: bool, streams?: array<string, list<string>>}>  $resolvedEntries
     *                                                                                                                              Keyed by route name — the manifest's already-computed TS-qualified path(s), only present for
     *                                                                                                                              realms whose manifest build succeeded (see class docblock on why a throwing realm degrades
     *                                                                                                                              gracefully rather than blinding the whole audit).
     * @param  ?string  $generatedDtsContent  `ui/src/types/generated.d.ts`'s content, or null when unreadable (degrades to
     *                                        check 1 only rather than crashing).
     */
    public function __construct(
        protected array $rawReferences,
        protected array $resolvedEntries,
        protected ?string $generatedDtsContent,
    ) {}

    /**
     * The default host wiring: walk the same `beam.client.sources.{defaults,admin}` realms
     * {@see SdkReturnsCoverageAudit}/{@see GenerateClientSdkCommand} already target, reading each
     * route's RAW action values directly off the router (never through the manifest, so a bad reference
     * can't hide the rest of that realm's routes from check 1) plus each realm's resolved manifest
     * entries (best-effort — a realm whose `toArray()` throws is simply skipped for check 2, not fatal).
     */
    public static function forApp(): self
    {
        $rawReferences = [];
        /** @var array<string, true> $seenRoutes */
        $seenRoutes = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || isset($seenRoutes[$name])) {
                continue;
            }
            $seenRoutes[$name] = true;

            $returns = $route->getAction('returns');
            if (is_string($returns) && $returns !== '') {
                $rawReferences[] = ['routeName' => $name, 'uri' => $route->uri(), 'source' => $name, 'field' => 'returns', 'class' => $returns];
            }

            $streams = $route->getAction('streams');
            if (is_array($streams)) {
                foreach ($streams as $eventName => $classes) {
                    foreach ((array) $classes as $class) {
                        if (is_string($class) && $class !== '') {
                            $rawReferences[] = ['routeName' => $name, 'uri' => $route->uri(), 'source' => $name, 'field' => "streams:{$eventName}", 'class' => $class];
                        }
                    }
                }
            }
        }

        $resolvedEntries = [];
        foreach (['defaults', 'admin'] as $realm) {
            $binding = config("beam.client.sources.{$realm}");
            if (! is_string($binding) || $binding === '' || ! class_exists($binding)) {
                continue;
            }
            $source = app($binding);
            if (! $source instanceof RouteManifestSource) {
                continue;
            }

            try {
                foreach ($source->toArray() as $name => $entry) {
                    $resolvedEntries[$name] = $entry;
                }
            } catch (\Throwable) {
                // A bad reference somewhere in this realm threw during manifest build (e.g.
                // ClientTypeName::for() on an unresolvable class) — check 1 (below) still catches it
                // via the raw route walk above; this realm just has no computed paths for check 2.
                continue;
            }
        }

        $dtsPath = base_path('ui/src/types/generated.d.ts');
        $dts = is_readable($dtsPath) ? (string) file_get_contents($dtsPath) : null;

        return new self($rawReferences, $resolvedEntries, $dts);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return $this->check($this->rawReferences, $this->resolvedEntries, $this->generatedDtsContent);
    }

    /**
     * The pure core — no router, no filesystem beyond the already-read `$generatedDtsContent` string.
     * Directly unit-testable with plain arrays.
     *
     * @param  list<array{routeName: ?string, uri: string, source: string, field: string, class: string}>  $rawReferences
     * @param  array<string, array{returns?: string, returnsMany?: bool, streams?: array<string, list<string>>}>  $resolvedEntries
     * @return list<Finding>
     */
    public function check(array $rawReferences, array $resolvedEntries, ?string $dtsContent): array
    {
        $findings = [];

        foreach ($rawReferences as $ref) {
            if (! class_exists($ref['class']) && ! interface_exists($ref['class']) && ! enum_exists($ref['class'])) {
                $findings[] = Finding::fail(self::CHECK, sprintf(
                    "%s [%s] %s references '%s', which does not resolve to a real class — likely a ".
                    'missing or wrong `use` import in the route file (PHP silently resolves an unimported '.
                    '`Foo::class` to the bare string "Foo" instead of erroring).',
                    $ref['routeName'] ?? $ref['uri'],
                    $ref['uri'],
                    $ref['field'],
                    $ref['class'],
                ));

                continue;
            }

            if ($dtsContent === null) {
                // No generated.d.ts to check against — check 1 already passed, nothing more to say.
                continue;
            }

            $entry = $resolvedEntries[$ref['routeName']] ?? null;
            if ($entry === null) {
                // This route's realm manifest build threw (see forApp()) — no computed path available
                // for this route specifically, so check 2 can't run for it. Not itself a finding: the
                // manifest-build failure would surface via whatever route actually threw.
                continue;
            }

            foreach ($this->tsPathsFor($ref['field'], $entry) as $tsPath) {
                if (! $this->resolvesInDts($tsPath, $dtsContent)) {
                    $findings[] = Finding::warn(self::CHECK, sprintf(
                        "%s [%s] %s → '%s' resolves as a real PHP class but no matching declaration was ".
                        'found in generated.d.ts — check `#[TypeScript]`, `HostFacingDataTransformer::ALWAYS_INCLUDE`, '.
                        'manifest-driven coverage, or whether the wrong one of two same-named classes is referenced.',
                        $ref['routeName'] ?? $ref['uri'],
                        $ref['uri'],
                        $ref['field'],
                        $tsPath,
                    ));
                }
            }
        }

        return $findings;
    }

    /**
     * The TS-qualified path(s) a manifest entry carries for the given raw field — `returns` yields at
     * most one, `streams:<eventName>` yields every class listed for that event name (the manifest keys
     * `streams` by event name → list of TS paths, mirroring the raw action's own shape).
     *
     * @param  array{returns?: string, returnsMany?: bool, streams?: array<string, list<string>>}  $entry
     * @return list<string>
     */
    protected function tsPathsFor(string $field, array $entry): array
    {
        if ($field === 'returns') {
            return isset($entry['returns']) ? [$entry['returns']] : [];
        }

        if (str_starts_with($field, 'streams:')) {
            $eventName = substr($field, strlen('streams:'));

            return $entry['streams'][$eventName] ?? [];
        }

        return [];
    }

    /**
     * Weak-but-proportionate structural check, not a full TS parser: the resolved type's LAST dot
     * segment (its exported name) must appear as an `interface X`/`type X` declaration SOMEWHERE in the
     * file, and the FIRST segment (the namespace root — `App`, or an unowned package's own root like
     * `Rushing`) must appear as a `namespace X` declaration. Doesn't verify exact nesting depth — every
     * real failure this session hit was "declaration entirely absent," not "present but mis-nested";
     * this is a backstop, not a full compiler.
     */
    protected function resolvesInDts(string $tsPath, string $dtsContent): bool
    {
        $segments = explode('.', $tsPath);
        if ($segments === []) {
            return false;
        }

        $name = end($segments);
        $root = $segments[0];

        $hasDeclaration = (bool) preg_match('/\b(?:interface|type)\s+'.preg_quote($name, '/').'\b/', $dtsContent);
        $hasRootNamespace = (bool) preg_match('/\bnamespace\s+'.preg_quote($root, '/').'\b/', $dtsContent);

        return $hasDeclaration && $hasRootNamespace;
    }
}
