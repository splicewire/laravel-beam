<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Surgeon\Rewrite\LiteralRewriteOperation;

/**
 * The host-aware SDK endpoint-drift audit (dogfood campaign). The published Saloon SDK
 * `splicewire/laravel-connector` has request classes whose `resolveEndpoint()` string literal can drift from the
 * app's REAL route table — the marquee case: a class returning `/api/v1/compositions/{id}/render` after
 * the app moved that route to `/api/v1/splice/compositions/{id}/render` (ADR-0124). Nothing caught it
 * until tests failed. This audit is the deterministic pre-pass that would have.
 *
 * It lives in BEAM, not in surgeon: surgeon is FOUNDATION tier and must never know "an SDK endpoint
 * should match a host route" (a `splicewire/laravel-connector` estate fact). Beam owns that estate POLICY and
 * nominates surgeon's generic {@see LiteralRewriteOperation} mechanism via the Finding→Operation
 * bridge — this audit emits a `literal-rewrite` {@see OperationSuggestion} carrying the exact
 * `{file, old, new}` splice.
 *
 * Matching is by **unique suffix**: for a drifted literal with no exact route match, the corrected path
 * is the one real route whose path ends with the SDK literal's own trailing segment-run (past the
 * `/api/v1` prefix). A unique hit is a fixable finding; zero or many is advisory (a Finding with a null
 * suggestion) — the deterministic/agentic seam, upheld: the audit never guesses an ambiguous target.
 */
class SdkEndpointDriftAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'sdk.endpoint-drift';

    public function __construct(
        protected string $requestsDir,
    ) {}

    /**
     * The plain finding-half for beam's own doctor command (the {@see DoctorAudit} channel): the same
     * diagnosis as {@see suggestOperations()} with the fix-suggestion dropped, so one manifest
     * registration serves both `splicewire:beam:doctor` and surgeon's `surgeon:audit` sweep.
     *
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f) => $f->finding, $this->suggestOperations());
    }

    /**
     * The default wiring against the generated Saloon SDK, resolved via its normal `vendor/` install
     * (a co-dev path-repo symlink locally, a plain git checkout in CI — either way composer already put
     * it there, so this needs no Workspaces-relative guessing). The package identity is config-driven
     * (`config('beam.client.surgeon.sdk_package')`, default `splicewire/laravel-connector`) so a different beam
     * host scanning its OWN generated SDK just overrides the config, not this class. Kept off the
     * constructor so the class is pure-unit testable
     * ({@see suggestFor()}) with an in-memory fixture — no package, no route table, no DB.
     */
    public static function forClientPackage(?string $requestsDir = null): self
    {
        $requestsDir ??= base_path('vendor/'.config('beam.client.surgeon.sdk_package', 'splicewire/laravel-connector').'/src/Requests');

        return new self($requestsDir);
    }

    /**
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array
    {
        return $this->suggestFor(
            $this->collectSdkLiterals($this->requestsDir),
            $this->collectRoutePaths(),
        );
    }

    /**
     * The pure core — no disk, no route facade. Given the SDK literals ({file, literal} rows) and the
     * app's real route paths, produce one {@see FixableFinding} per drifted literal. Directly unit
     * testable on the ADR-0124 case.
     *
     * @param  list<array{file: string, literal: string}>  $sdkLiterals
     * @param  list<string>  $routePaths  the app's real route uris (e.g. `api/v1/splice/compositions/{id}/render`)
     * @return list<FixableFinding>
     */
    public function suggestFor(array $sdkLiterals, array $routePaths): array
    {
        $normalizedRoutes = array_map(fn ($p) => $this->normalize($p), $routePaths);
        $routeSet = array_flip($normalizedRoutes);

        $findings = [];
        foreach ($sdkLiterals as $row) {
            $literal = $row['literal'];
            $normalized = $this->normalize($this->routeShape($literal));

            // Exact match — no drift.
            if (isset($routeSet[$normalized])) {
                continue;
            }

            $matches = $this->uniqueSuffixMatches($normalized, $normalizedRoutes);

            if (count($matches) === 1) {
                $corrected = $this->withLeadingSlash($matches[0]);
                $findings[] = new FixableFinding(
                    Finding::fail(self::CHECK, sprintf(
                        'SDK endpoint %s in %s does not match any app route; corrected to %s (unique suffix match).',
                        $literal,
                        basename($row['file']),
                        $corrected,
                    )),
                    new OperationSuggestion(
                        kind: 'literal-rewrite',
                        summary: "Repoint drifted SDK endpoint {$literal} → {$corrected}",
                        payload: [
                            'file' => $row['file'],
                            'old' => $literal,
                            'new' => $this->reshapeToLiteral($literal, $corrected),
                        ],
                    ),
                );

                continue;
            }

            // Zero or ambiguous — advisory: a real drift the audit can see but not safely auto-fix.
            $findings[] = new FixableFinding(
                Finding::fail(self::CHECK, sprintf(
                    'SDK endpoint %s in %s does not match any app route and has %s — manual review.',
                    $literal,
                    basename($row['file']),
                    $matches === [] ? 'no unique suffix match' : count($matches).' candidate routes',
                )),
                null,
            );
        }

        return $findings;
    }

    /**
     * Every real app route's uri. The SDK addresses more than `/api/v1` — admin (`/api/admin/*`) and
     * other-prefix surfaces (`/api/schema-forms/*`) too — so collect ALL uris, not just the version
     * prefix; an omitted surface would otherwise read as drift-by-absence.
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
     * Extract the `resolveEndpoint()` returned string literal from every request class under a dir.
     * Regex is fine (per the contract) — the literal is a single-line `return '...';` / `return "...";`.
     *
     * @return list<array{file: string, literal: string}>
     */
    protected function collectSdkLiterals(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $literals = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $literal = $this->extractEndpointLiteral((string) file_get_contents($file->getPathname()));
            if ($literal !== null) {
                $literals[] = ['file' => $file->getPathname(), 'literal' => $literal];
            }
        }

        return $literals;
    }

    /**
     * The `resolveEndpoint()` literal as written in source (keeping its `{$this->id}` interpolation),
     * or null if the class has no static single-literal endpoint.
     */
    public function extractEndpointLiteral(string $source): ?string
    {
        if (! preg_match('/function\s+resolveEndpoint\b/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $tail = substr($source, $m[0][1]);

        // The endpoint expression: a return that STARTS with a string literal (`'` or `"`), captured
        // whole up to the terminating `;`. Covers the plain `return '/api/x';`, the mid-path concat
        // `return '/api/x/'.$this->id.'/y';`, AND the trailing concat `return '/api/x/'.$this->id;`.
        // The idiom is kept verbatim; routeShape() normalizes concat/interpolation to `{param}`.
        if (! preg_match('/return\s+([\'"].*?)\s*;/s', $tail, $lit)) {
            return null;
        }

        // Strip the outer boundary quotes independently — a plain literal returns as `/api/x`
        // (unchanged behaviour), a concat sheds only its leading/trailing quote and keeps its interior
        // `'.<expr>.'` seams (which routeShape() collapses). Interior quotes never touch the boundary.
        $expr = $lit[1];
        $expr = preg_replace('/^[\'"]/', '', $expr) ?? $expr;
        $expr = preg_replace('/[\'"]$/', '', $expr) ?? $expr;

        return $expr;
    }

    /**
     * The route-shaped form of an SDK literal. SDK requests express a path param two ways: `{$this->id}`
     * string interpolation AND PHP string concatenation (`'.$this->id.'`, `'.ltrim($this->stem, '/').'`).
     * Collapse BOTH to a `{param}` placeholder — the concat segment (a `'.<expr>.'` seam between two
     * quoted pieces) first, then any interpolation — so either shape compares against a real route's
     * `{id}` uri segment.
     */
    public function routeShape(string $literal): string
    {
        // Concat seam: a quote, `.`, an expression, then either `.` + a re-opening quote (mid-path) or
        // end-of-literal (a trailing `'.$this->id`). Non-greedy so `ltrim($x, '/')` internal quotes
        // don't end the seam early, and multiple seams each collapse independently.
        $shaped = preg_replace('/[\'"]\s*\.\s*.+?(?:\s*\.\s*[\'"]|$)/', '{param}', $literal) ?? $literal;

        return preg_replace('/\{[^}]*\}/', '{param}', $shaped) ?? $shaped;
    }

    /**
     * Re-apply the SDK literal's ORIGINAL interpolation tokens onto the corrected route path, so the
     * rewrite preserves `{$this->id}` exactly rather than writing a route-style `{id}`. Positional:
     * the nth placeholder in the corrected path takes the nth token from the original literal.
     */
    protected function reshapeToLiteral(string $originalLiteral, string $correctedPath): string
    {
        // Capture the original's param tokens IN ORDER — either interpolation (`{$this->id}`) or a
        // concat seam (`'.$this->id.'`) — so the corrected literal preserves the source's own idiom.
        preg_match_all('/\{[^}]*\}|[\'"]\s*\.\s*.+?(?:\s*\.\s*[\'"]|$)/', $originalLiteral, $tokens);
        $tokens = $tokens[0];
        $i = 0;

        return preg_replace_callback('/\{[^}]*\}/', function ($m) use ($tokens, &$i) {
            return $tokens[$i++] ?? $m[0];
        }, $correctedPath) ?? $correctedPath;
    }

    /**
     * The real routes whose normalized path ends with the SDK literal's own trailing segment-run past
     * the version prefix — the "unique suffix" candidates.
     *
     * @param  list<string>  $normalizedRoutes
     * @return list<string>
     */
    protected function uniqueSuffixMatches(string $normalizedLiteral, array $normalizedRoutes): array
    {
        $suffix = $this->suffixPastVersion($normalizedLiteral);

        return array_values(array_filter(
            $normalizedRoutes,
            fn ($route) => str_ends_with($route, $suffix) && $route !== $normalizedLiteral,
        ));
    }

    /** The literal's path past the `api/v1` version prefix — the segment-run that must survive a move. */
    protected function suffixPastVersion(string $normalized): string
    {
        if (preg_match('#^api/v\d+/(.*)$#', $normalized, $m)) {
            return $m[1];
        }

        return $normalized;
    }

    /**
     * Slash-insensitive, param-name-insensitive route path — trim boundary slashes and collapse every
     * `{...}` segment (a route's `{id}` and the SDK's `{$this->id}`) to a single `{param}` placeholder,
     * so the two vocabularies compare on path shape alone.
     */
    protected function normalize(string $path): string
    {
        return preg_replace('/\{[^}]*\}/', '{param}', trim($path, '/')) ?? trim($path, '/');
    }

    protected function withLeadingSlash(string $path): string
    {
        return '/'.ltrim($path, '/');
    }
}
