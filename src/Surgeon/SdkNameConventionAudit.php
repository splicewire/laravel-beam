<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\RelocationOperation;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\Codegen\SdkNaming;
use Symfony\Component\Yaml\Yaml;

/**
 * The SDK NAME-CONVENTION audit (client-sdk-regen "convention drives SDK names" pivot). The published
 * `splicewire/laravel-connector` request classes were originally hand-picked; the pivot makes the GENERATOR's
 * convention ({@see SdkNaming}) the standard and reconciles the EXISTING names TO it via surgeon renames.
 *
 * This is the deterministic pre-pass that PRODUCES that rename list. For each existing
 * `Splicewire\Client\Requests\<Domain>\<Class>` class it reconstructs the `op` shape {@see SdkNaming}
 * needs — the request's HTTP verb + `resolveEndpoint()` literal give (method, path); the path is matched
 * against the app's real route table (reusing {@see SdkEndpointDriftAudit}'s literal→route-shape logic)
 * and the matched route's `@group` tag (from the openapi spec) gives the domain — then computes the
 * convention `<Domain>/<ConventionClass>`. Where CURRENT ≠ convention it emits a
 * `FixableFinding(Finding::warn('sdk.name-nonconventional', …), OperationSuggestion('relocation', …))`
 * carrying the `{oldFqn, newFqn}` a `surgeon:move` performs. A class already at its convention name → no
 * finding.
 *
 * It lives in BEAM, not surgeon: surgeon (foundation tier) must never know "an SDK class name should
 * match the generator convention" (a `splicewire/laravel-connector` estate fact). Beam owns that estate POLICY and
 * nominates surgeon's generic {@see RelocationOperation} via the
 * Finding→Operation bridge — exactly like the sibling {@see SdkEndpointDriftAudit}.
 *
 * Advisory seam (the deterministic/agentic boundary): a class whose endpoint literal matches NO app route
 * (the known drift cases) can't have its convention name resolved — the audit emits a plain advisory
 * Finding with a NULL suggestion rather than guessing.
 *
 * ## Live-but-unspecced routes are NOT drift (the false-positive fix)
 * The convention name derives from the openapi spec's `@group` tag, but the spec deliberately covers less
 * than the live route table (scribe matches a prefix set minus an exclusion list — e.g. a first-party
 * login bootstrap, an RFC 8628 device-pairing pair, package-registered non-v1 surfaces). A request whose
 * literal DOES match a live route that simply carries no spec tag was previously lumped into the
 * "matches no app route/tag" drift WARN. Now the two legs split:
 *   - the route is declared in `beam.client.surgeon.spec_exclusions` (a `pattern => citation` map, `*`
 *     wildcards) → a Pass line: "excluded from the openapi spec by <citation>" — spec-derived naming does
 *     not apply, honestly ledgered, not drift;
 *   - the route is live but neither specced nor declared excluded → still a WARN, now naming the real
 *     condition (a spec gap or an undeclared exclusion) instead of a fictitious missing route.
 */
class SdkNameConventionAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'sdk.name-nonconventional';

    public const CLIENT_NAMESPACE = 'Splicewire\\Client\\Requests';

    /**
     * @param  array<string, string>  $specExclusions  route-uri pattern (`*` wildcards, no leading slash)
     *                                                 => the citation naming WHY that live surface is
     *                                                 outside the openapi spec (an ADR, "package-registered
     *                                                 surface", …) — surfaced verbatim on the Pass line
     */
    public function __construct(
        protected string $requestsDir,
        protected string $openApiPath,
        protected string $clientNamespace = self::CLIENT_NAMESPACE,
        protected array $specExclusions = [],
    ) {}

    /**
     * The default wiring against the generated Saloon SDK's normal `vendor/` install + the host's
     * scribe openapi spec. The package identity is config-driven
     * (`config('beam.client.surgeon.sdk_package')`/`sdk_namespace`, defaulting to this repo's own
     * `splicewire/laravel-connector`) so a different beam host scanning its OWN generated SDK just overrides the
     * config, not this class — mirrors {@see SdkEndpointDriftAudit::forClientPackage()}. Kept off the
     * constructor so the class is pure-unit testable ({@see suggestFor()}).
     */
    public static function forClientPackage(?string $requestsDir = null, ?string $openApiPath = null, ?string $clientNamespace = null): self
    {
        $requestsDir ??= base_path('vendor/'.config('beam.client.surgeon.sdk_package', 'splicewire/laravel-connector').'/src/Requests');
        $openApiPath ??= storage_path('app/scribe/openapi.yaml');
        $clientNamespace ??= config('beam.client.surgeon.sdk_namespace', self::CLIENT_NAMESPACE);

        return new self(
            $requestsDir,
            $openApiPath,
            $clientNamespace,
            (array) config('beam.client.surgeon.spec_exclusions', []),
        );
    }

    /**
     * The plain finding-half for beam's own doctor command ({@see DoctorAudit}) — the same diagnosis as
     * {@see suggestOperations()} with the fix-suggestion dropped, so one manifest registration serves
     * both `splicewire:beam:doctor` and surgeon's `surgeon:audit` sweep.
     *
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f) => $f->finding, $this->suggestOperations());
    }

    /**
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array
    {
        return $this->suggestFor(
            $this->collectSdkClasses($this->requestsDir),
            $this->collectRoutePaths(),
            $this->collectTagsByRoute(),
        );
    }

    /**
     * The pure core — no disk, no route facade, no spec parse. Given the existing SDK classes (each with
     * its current domain/class/verb/endpoint literal), the app's real route paths, and the (verb, route-
     * shape) → `@group` tag map, produce one {@see FixableFinding} per class whose CURRENT name diverges
     * from the {@see SdkNaming} convention.
     *
     * Directly unit testable: a divergent class nominates a `relocation` with the correct new FQN; a
     * conventional class yields no finding; an unresolvable (no-route) class is a null-suggestion advisory.
     *
     * @param  list<array{fqn: string, domain: string, class: string, method: string, literal: string}>  $sdkClasses
     * @param  list<string>  $routePaths  the app's real route uris (e.g. `api/v1/splice/compositions/{id}`)
     * @param  array<string, string>  $tagsByRoute  key `"{VERB} {route-shape}"` → the `@group` tag
     * @return list<FixableFinding>
     */
    public function suggestFor(array $sdkClasses, array $routePaths, array $tagsByRoute): array
    {
        $normalizedRoutes = [];
        foreach ($routePaths as $p) {
            $normalizedRoutes[$this->normalize($p)] = $p;
        }

        $naming = new SdkNaming;
        $findings = [];

        foreach ($sdkClasses as $sdk) {
            $normalizedLiteral = $this->normalize($this->routeShape($sdk['literal']));

            // Resolve the literal to a real route (the path the convention names off). Exact
            // route-shape match only — an unresolvable literal is a drift case, left advisory.
            $matchedRoute = $normalizedRoutes[$normalizedLiteral] ?? null;
            $verb = strtoupper($sdk['method']);
            $tag = $matchedRoute !== null
                ? ($tagsByRoute[$verb.' '.$this->normalize($matchedRoute)] ?? null)
                : null;

            if ($matchedRoute === null) {
                $findings[] = new FixableFinding(
                    Finding::warn(self::CHECK, sprintf(
                        'SDK request %s addresses %s, which matches no app route — convention name '.
                        'cannot be resolved (drift); manual review.',
                        $sdk['fqn'],
                        $sdk['literal'],
                    )),
                    null,
                );

                continue;
            }

            if ($tag === null) {
                // The route is LIVE — it just isn't in the openapi spec. Declared exclusion → an
                // honestly-ledgered Pass naming the citation; undeclared → still a WARN, but naming
                // the real condition (spec gap / undeclared exclusion), never a fictitious no-route.
                $citation = $this->specExclusionCitation($matchedRoute);

                $findings[] = new FixableFinding(
                    $citation !== null
                        ? Finding::pass(self::CHECK, sprintf(
                            'SDK request %s addresses %s, a live route excluded from the openapi spec by %s '.
                            '— spec-derived convention naming does not apply; not drift.',
                            $sdk['fqn'],
                            $matchedRoute,
                            $citation,
                        ))
                        : Finding::warn(self::CHECK, sprintf(
                            'SDK request %s addresses %s, a live route with no openapi spec tag — either the '.
                            'spec is stale (regenerate) or the surface is intentionally unspecced (declare it '.
                            'in beam.client.surgeon.spec_exclusions with its citation); convention name '.
                            'cannot be resolved.',
                            $sdk['fqn'],
                            $matchedRoute,
                        )),
                    null,
                );

                continue;
            }

            $op = [
                'method' => $verb,
                'path' => $matchedRoute,
                'meta' => ['tags' => [$tag]],
            ];
            $conventionClass = $naming->classNameFor($op);

            // The DOMAIN is not audited — it is registry data (`codegen.options.splicewire-client.domains`),
            // not a convention derivable from the spec. It used to be taken from the studly `@group` tag,
            // which api-surface-coherence 88 measured as wrong three ways: the tag moves under host-owned
            // taxonomy edits, one tag can back several SDK domains, and a tag like `Review & Status` studlies
            // to `Review&Status` — an illegal namespace segment this audit would have nominated a rename TO.
            // So the class keeps its own domain and only its SHORT NAME is reconciled to the convention.
            $newFqn = $this->clientNamespace.'\\'.$sdk['domain'].'\\'.$conventionClass;

            // Already at its convention FQN — no finding.
            if ($sdk['fqn'] === $newFqn) {
                continue;
            }

            $findings[] = new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    'SDK request %s is non-conventional; convention (from %s %s / @group %s) is %s.',
                    $sdk['fqn'],
                    $verb,
                    $matchedRoute,
                    $tag,
                    $newFqn,
                )),
                new OperationSuggestion(
                    kind: 'relocation',
                    summary: "Rename SDK request {$sdk['fqn']} → {$newFqn} (convention)",
                    payload: [
                        'oldFqn' => $sdk['fqn'],
                        'newFqn' => $newFqn,
                    ],
                ),
            );
        }

        return $findings;
    }

    /**
     * The citation for a live route the host DECLARED outside its openapi spec, or null when the route is
     * not declared excluded. Patterns are `Str::is()` globs matched slash-trimmed (so `api/device/*` and
     * `/api/device/*` behave identically).
     */
    protected function specExclusionCitation(string $routeUri): ?string
    {
        $uri = trim($routeUri, '/');

        foreach ($this->specExclusions as $pattern => $citation) {
            if (Str::is(trim((string) $pattern, '/'), $uri)) {
                return (string) $citation;
            }
        }

        return null;
    }

    /**
     * Every real app route's uri.
     *
     * @return list<string>
     */
    protected function collectRoutePaths(): array
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            $paths[$route->uri()] = true;
        }

        return array_keys($paths);
    }

    /**
     * Parse the scribe openapi spec into a `"{VERB} {route-shape}"` → first-`tag` map. The spec's
     * `paths.{path}.{verb}.tags[0]` is the `@group`; the path is route-shaped (`{id}` → `{param}`) so it
     * keys the same way the route table normalizes.
     *
     * @return array<string, string>
     */
    protected function collectTagsByRoute(): array
    {
        if (! is_file($this->openApiPath)) {
            return [];
        }

        $spec = $this->parseOpenApi((string) file_get_contents($this->openApiPath));

        return $this->tagsFromSpec($spec);
    }

    /**
     * Pure spec → tag-map projection (unit-testable off a parsed array).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, string>
     */
    public function tagsFromSpec(array $spec): array
    {
        $map = [];
        foreach (($spec['paths'] ?? []) as $path => $verbs) {
            if (! is_array($verbs)) {
                continue;
            }
            $shape = $this->normalize((string) $path);
            foreach ($verbs as $verb => $op) {
                if (! is_array($op) || ! isset($op['tags'][0])) {
                    continue;
                }
                $map[strtoupper((string) $verb).' '.$shape] = (string) $op['tags'][0];
            }
        }

        return $map;
    }

    /**
     * Minimal YAML→array parse of the openapi spec, sufficient for the `paths → verb → tags` slice this
     * audit reads. Uses `symfony/yaml` when present (it is, transitively, in the estate); otherwise falls
     * back to a JSON decode (the spec doubles as `openapi.json` in some setups).
     *
     * @return array<string, mixed>
     */
    protected function parseOpenApi(string $contents): array
    {
        if (class_exists(Yaml::class)) {
            $parsed = Yaml::parse($contents);

            return is_array($parsed) ? $parsed : [];
        }

        $json = json_decode($contents, true);

        return is_array($json) ? $json : [];
    }

    /**
     * Extract each request class under a dir into the `op`-reconstruction row {@see suggestFor()} needs:
     * its FQN, current domain + class short-name (from namespace/path), HTTP verb, and endpoint literal.
     *
     * @return list<array{fqn: string, domain: string, class: string, method: string, literal: string}>
     */
    protected function collectSdkClasses(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $rows = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $row = $this->parseSdkClass($source);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Reconstruct one SDK class's `op` inputs from its source: `namespace …\Requests\<Domain>`, `class
     * <Class>`, `Method::<VERB>`, and the `resolveEndpoint()` literal. Returns null when it's not a
     * resolvable request class (no verb / no endpoint literal).
     *
     * @return array{fqn: string, domain: string, class: string, method: string, literal: string}|null
     */
    public function parseSdkClass(string $source): ?array
    {
        if (! preg_match('/namespace\s+([^;]+);/', $source, $nm)) {
            return null;
        }
        $namespace = trim($nm[1]);
        if (! preg_match('/\bclass\s+(\w+)/', $source, $cm)) {
            return null;
        }
        $class = $cm[1];

        // The HTTP verb — `protected Method $method = Method::POST;` (property) or a `Method::GET`
        // reference. Default to GET when a request declares none (Saloon's default).
        $verb = 'GET';
        if (preg_match('/Method::([A-Z]+)/', $source, $vm)) {
            $verb = $vm[1];
        }

        $literal = $this->extractEndpointLiteral($source);
        if ($literal === null) {
            return null;
        }

        return [
            'fqn' => $namespace.'\\'.$class,
            'domain' => (string) preg_replace('#^.*\\\\Requests\\\\#', '', $namespace),
            'class' => $class,
            'method' => $verb,
            'literal' => $literal,
        ];
    }

    // ── literal extraction + route-shape normalization (shared shape with SdkEndpointDriftAudit) ────────

    /**
     * The `resolveEndpoint()` literal as written in source (keeping its `{$this->id}` interpolation), or
     * null if the class has no static single-literal endpoint. Same idiom as {@see SdkEndpointDriftAudit}.
     */
    public function extractEndpointLiteral(string $source): ?string
    {
        if (! preg_match('/function\s+resolveEndpoint\b/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $tail = substr($source, $m[0][1]);
        if (! preg_match('/return\s+([\'"].*?)\s*;/s', $tail, $lit)) {
            return null;
        }

        $expr = $lit[1];
        $expr = preg_replace('/^[\'"]/', '', $expr) ?? $expr;
        $expr = preg_replace('/[\'"]$/', '', $expr) ?? $expr;

        return $expr;
    }

    /**
     * The route-shaped form of an SDK literal — collapse concat seams (`'.$this->id.'`) then
     * interpolation (`{$this->id}`) to `{param}`, so it compares against a real route's `{id}` segment.
     */
    public function routeShape(string $literal): string
    {
        $shaped = preg_replace('/[\'"]\s*\.\s*.+?(?:\s*\.\s*[\'"]|$)/', '{param}', $literal) ?? $literal;

        return preg_replace('/\{[^}]*\}/', '{param}', $shaped) ?? $shaped;
    }

    /**
     * Slash-insensitive, param-name-insensitive route path.
     */
    public function normalize(string $path): string
    {
        return preg_replace('/\{[^}]*\}/', '{param}', trim($path, '/')) ?? trim($path, '/');
    }
}
