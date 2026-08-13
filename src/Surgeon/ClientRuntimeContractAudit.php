<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;

/**
 * The client-runtime contract audit (particle-doctrine-followups #12). The generated client imports two
 * modules the generator only NAMES — `beam.client.client_import` (the http clients) and
 * `beam.client.routes_import` (the route resolvers) — and no beam package writes them: the host owns
 * both. An unowned, unaudited contract is exactly where the two live implementations diverged, so this
 * audit makes the contract checkable where the host will see it (`splicewire:beam:doctor` /
 * `surgeon:audit`), following the `laravel-beam-ux-prototype` wiring precedent: a PHP-side STATIC
 * inspection of host JS, registered advisory into {@see BeamDoctorManifest}.
 *
 * The contract itself (docs/client-runtime-contract.md):
 *   - `client_import` must export `api` — `.get(url)` / `.post(url, body)` / `.put(url, body)` /
 *     `.patch(url, body)` / `.delete(url)`, each resolving to a response whose `res.data.data` is the
 *     payload — plus `operatorApi` (same shape) when the host binds an operator manifest source.
 *   - `routes_import` must export `route(name, params?): string` — plus `operatorRoute` when the
 *     operator tier is bound. (`RouteMap` is NO LONGER part of the host contract: the generator owns
 *     and emits its own `RouteMap` in `routes.ts`, so the type dependency no longer flows backwards.)
 *
 * Static reach, honestly stated: this checks module PRESENCE and export NAMES via line-anchored
 * patterns — the same proportionate, no-bundler discipline as the prototype's CSS-token audit. Shape
 * conformance beyond export names (envelope re-wrapping, thrown-vs-templated param behavior) would need
 * the JS toolchain and would ride the existing `SdkHookMigrationBridge` Node-bin seam; that depth is
 * deliberately not fabricated here. Divergence between hosts (axios vs `fetch`, an operator client or
 * not) is legitimate per-host variation and is NOT reported — only a missing module or a missing
 * required export is.
 *
 * Module specifiers under the host's `@/` alias resolve against the parent of `beam.client.out_dir`
 * (the alias root both live layouts use: `ui/src` on the platform, `resources/js` on a satellite). Any
 * other specifier (an npm package, a relative path) is skipped with a pass-level note —
 * degrade-not-fabricate, like the hook-migration bridge when its script is unset.
 *
 * Plain {@see DoctorAudit}, advisory, no `SuggestsOperations` — the fix is `vendor:publish
 * --tag=beam-client-runtime` (or writing the module), not a byte-splice.
 */
class ClientRuntimeContractAudit implements DoctorAudit
{
    public const CHECK = 'sdk.client-runtime-contract';

    public function __construct(
        protected string $clientImport,
        protected string $routesImport,
        protected ?string $clientModulePath,
        protected ?string $routesModulePath,
        protected ?string $clientModuleContent,
        protected ?string $routesModuleContent,
        protected bool $operatorTier,
    ) {}

    public static function forApp(): self
    {
        $outDir = config('beam.client.out_dir');
        $outDir = is_string($outDir) && $outDir !== '' ? $outDir : base_path('resources/js/generated');
        $jsRoot = dirname($outDir);

        $clientImport = self::configuredImport('beam.client.client_import', '@/lib/api');
        $routesImport = self::configuredImport('beam.client.routes_import', '@/lib/routes');

        $clientPath = self::resolveModule($jsRoot, $clientImport);
        $routesPath = self::resolveModule($jsRoot, $routesImport);

        return new self(
            clientImport: $clientImport,
            routesImport: $routesImport,
            clientModulePath: $clientPath,
            routesModulePath: $routesPath,
            clientModuleContent: $clientPath !== null && is_readable($clientPath) ? (string) file_get_contents($clientPath) : null,
            routesModuleContent: $routesPath !== null && is_readable($routesPath) ? (string) file_get_contents($routesPath) : null,
            operatorTier: is_string(config('beam.client.sources.operator')) && config('beam.client.sources.operator') !== '',
        );
    }

    /** @return list<Finding> */
    public function run(): array
    {
        // Fixed module order (client, then routes) keeps the rendered report byte-stable run to run.
        return array_merge(
            $this->checkModule($this->clientImport, $this->clientModulePath, $this->clientModuleContent, $this->clientSymbols()),
            $this->checkModule($this->routesImport, $this->routesModulePath, $this->routesModuleContent, $this->routesSymbols()),
        );
    }

    /** @return list<string> */
    protected function clientSymbols(): array
    {
        return $this->operatorTier ? ['api', 'operatorApi'] : ['api'];
    }

    /** @return list<string> */
    protected function routesSymbols(): array
    {
        return $this->operatorTier ? ['route', 'operatorRoute'] : ['route'];
    }

    /**
     * @param  list<string>  $symbols
     * @return list<Finding>
     */
    protected function checkModule(string $specifier, ?string $path, ?string $content, array $symbols): array
    {
        if ($path === null) {
            // Not under the `@/` alias — an npm package or exotic layout this static audit cannot
            // resolve. Skipping is stated, never silent: an unavailable check must not look satisfied.
            return [Finding::pass(self::CHECK, sprintf(
                "'%s' is not under the host's `@/` alias — not statically resolvable; contract check skipped (degrade-not-fabricate).",
                $specifier,
            ))];
        }

        if ($content === null) {
            return [Finding::warn(self::CHECK, sprintf(
                "'%s' resolves to %s, which does not exist — every generated hook imports it, so the ".
                'generated client cannot compile. Write the module or publish the reference runtime: '.
                '`php artisan vendor:publish --tag=beam-client-runtime`.',
                $specifier,
                $path,
            ))];
        }

        $missing = array_values(array_filter($symbols, fn (string $symbol) => ! $this->exportsSymbol($content, $symbol)));

        if ($missing !== []) {
            return [Finding::warn(self::CHECK, sprintf(
                "'%s' (%s) does not export %s — the generated client imports %s from it and will not resolve.",
                $specifier,
                $path,
                implode(', ', array_map(fn ($s) => "`{$s}`", $missing)),
                count($symbols) > 1 ? 'them' : 'it',
            ))];
        }

        return [Finding::pass(self::CHECK, sprintf(
            "'%s' satisfies the client-runtime contract (exports %s).",
            $specifier,
            implode(', ', array_map(fn ($s) => "`{$s}`", $symbols)),
        ))];
    }

    /**
     * Does the module statically export a symbol under this name? Matches declaration exports
     * (`export const api`, `export function route`, `export async function`, `export type`, …) and
     * brace re-exports (`export { api }`, `export { x as api }`). Line-anchored text patterns, not a
     * TS parser — the same proportionate discipline as the prototype's token audit; a host exporting
     * through deeper indirection reads as missing, and the advisory tier makes that a nudge, not a block.
     */
    protected function exportsSymbol(string $content, string $symbol): bool
    {
        $q = preg_quote($symbol, '/');

        if (preg_match('/^\s*export\s+(?:declare\s+)?(?:const|let|var|async\s+function|function|class|type|interface|enum)\s+'.$q.'\b/m', $content) === 1) {
            return true;
        }

        // `export { api }` / `export { client as api }` / `export type { RouteMap }` — the symbol must
        // be the EXPORTED name, i.e. not immediately followed by an `as` rename.
        return preg_match('/^\s*export\s+(?:type\s+)?\{[^}]*\b'.$q.'\b(?!\s+as\b)[^}]*\}/m', $content) === 1;
    }

    protected static function configuredImport(string $key, string $default): string
    {
        $v = config($key);

        return is_string($v) && $v !== '' ? $v : $default;
    }

    /** Resolve an `@/`-aliased specifier to a file under the alias root, trying the live extensions. */
    protected static function resolveModule(string $jsRoot, string $specifier): ?string
    {
        if (! str_starts_with($specifier, '@/')) {
            return null;
        }

        $stem = $jsRoot.'/'.substr($specifier, 2);

        foreach (['.ts', '.tsx', '.js', '.mjs', '/index.ts', '/index.tsx'] as $suffix) {
            if (is_file($stem.$suffix)) {
                return $stem.$suffix;
            }
        }

        // Missing module: report the conventional path so the finding names a concrete fix target.
        return $stem.'.ts';
    }
}
