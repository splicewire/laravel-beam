<?php

namespace Splicewire\Beam\Testing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The host information-architecture seam, as one architecture test (ux-walkthrough SPEC §5.1, ticket UX-01).
 *
 * A host's `tests/Architecture/HostIaSeamTest.php` uses this trait and calls {@see assertHostIaSeamRatchet()}. The
 * trait computes every violation of T1–T6 at that host and compares the set with the host's RATCHET, the list of
 * known violations, each owned by the UX ticket that removes it:
 *
 * - a violation that is not listed fails (a regression);
 * - a listed entry whose violation is gone fails too (stale), so the list only shrinks;
 * - an exact match passes, and every listed entry is printed with its owner, so the red stays visible.
 *
 * It never writes: no file, no DB row, no artifact (`regenerating-committed-artifacts.md`).
 *
 * Violation ids are stable across edits (no line numbers): `T<n> <what> <where>`.
 *
 * Structural until their modules exist, as the SPEC's UX-01 row records:
 * - T2 checks I1 and I4 over the host's nav.yml rows only, not each demo principal's projected rails. UX-06
 *   (`IaInvariants`) turns it into the full walk.
 * - T3 checks that the landing resolver exists and that Fortify's `home` is not a literal path. UX-11 (`Landing::for()`)
 *   turns it into the A5 door matrix.
 * - T4 recognises a macro's side by the routes it registers, until UX-07 gives macros a declared `Side`.
 * - T6 sweeps with `grep -rIF`, which keeps `rg -F`'s exit contract (1 = clean, 0 = matches, 2 = a failed sweep).
 *   ripgrep is not installed on the primary Mac.
 */
trait AssertsHostIaSeam
{
    /** @var list<string> T6 roots that did not exist at this run; their ratchet entries are unjudged. */
    private array $hostIaAbsentRoots = [];

    /**
     * The host's known violations: violation id => "UX-NN: why", owned by the ticket that removes it.
     *
     * @return array<string, string>
     */
    abstract protected function hostIaRatchet(): array;

    /**
     * Which side of a cross-instance relationship this host plays (`hub`, `client`), until `beam.core.ia.plays`
     * exists (UX-07). Tower and the flagship are hubs, satellites clients, the beam starter neither.
     *
     * @return list<'hub'|'client'>
     */
    abstract protected function hostIaPlays(): array;

    /**
     * Roots T6 sweeps, relative to the host's base path (or absolute): source roots, then built bundles. A root that does
     * not exist at this run (a gitignored build before a build), or a `node_modules` package that is a published copy
     * rather than the js-overlay link, is NAMED in the output,
     * and the ratchet entries under it are unjudged rather than stale. Absence is not a fix.
     *
     * @return array{source: list<string>, built: list<string>}
     */
    protected function hostIaSweepRoots(): array
    {
        return [
            'source' => ['app', 'config', 'resources/js', 'ui/src', '.env.example', 'node_modules/@splicewire/beam-inertia/src'],
            'built' => ['public/build', 'public/ui/assets'],
        ];
    }

    /** The host's nav.yml, if it has one. */
    protected function hostIaNavYml(): string
    {
        return base_path('resources/beam-ux/nav.yml');
    }

    /** T6's literals: import- and call-shaped, because a bare `beam-ux/nav` pattern matches prose (R2). */
    protected function hostIaForbiddenLiterals(): array
    {
        return [
            "navigate('/operator')",
            'href="/operator"',
            "navigate('/tenants'",
            "startsWith('/operator')",
            'react-starter-kit',
            'Beam Starter',
            'APP_NAME=Laravel',
            "|| 'Laravel'",
            // app-walkthrough APP-24: a package surface renders only where it is true (APP-11 removes it).
            '<SiteFixture',
            // docs-walkthrough D-T1 (source and built): docs chrome is the package's, not a host's (DOCS-12).
            'slots: { header',
            'function DocsHeader',
            'function BeamCta',
            'function ThemedApiReference',
            ...$this->hostIaHostLiterals(),
        ];
    }

    /**
     * Literals that apply only under some sweep roots: literal => the roots (as `hostIaSweepRoots()` names them) it is
     * judged in. Elsewhere the same word can be legitimate, such as an eval command's corpus slug or a docs page. A scope
     * may also be a SUBPATH of a root (`app/Navigation` under `app`): the literal is then judged only in files under it.
     *
     * @return array<string, list<string>>
     */
    protected function hostIaScopedLiterals(): array
    {
        // APP-25: no vertical is compiled into the host SPA or a family package's product source (APP-12 removes them).
        $shell = ['ui/src', 'node_modules/@splicewire/beam-inertia/src', 'public/ui/assets', 'public/build'];
        $hostSource = ['app', 'config', 'resources/js', 'ui/src'];

        return [
            'FOOD_SAFETY' => $shell,
            'food-safety' => $shell,
            'FoodWire' => $shell,
            'Food Code' => $shell,
            'COAs' => $shell,
            // APP-26: no host guesses a schema authority (APP-13 removes it).
            "env('SCHEMA_BASE_URI', '" => ['config'],
            // docs-walkthrough D-T2: no host SOURCE themes the API reference (DOCS-13). Source only: a built bundle
            // legitimately carries the reference library's own variables.
            'darkMode:' => $hostSource,
            'hideDarkModeToggle' => $hostSource,
            '--scalar-' => $hostSource,
            // app-walkthrough APP-01: hand IA tables and paths the generated routers and projected nav replace.
            'SECTION_META' => ['ui/src'],             // APP-17
            'META_AREAS' => ['ui/src'],               // APP-17
            'TenantManifestLeaf' => ['ui/src'],       // APP-16
            'export const operatorRealm' => ['ui/src'], // APP-14
            // APP-14: the footer's rendered `tenant: …` text. JSX-text form, so an object key named `tenant` is not a hit.
            '>tenant: {' => ['ui/src'],               // APP-14
            // APP-1: a place's href is minted, never written; judged over app/Navigation only (a SUBPATH scope).
            "'href' =>" => ['app/Navigation'],         // APP-15
        ];
    }

    /** @return list<string> the literals judged under this sweep root */
    private function hostIaLiteralsFor(string $root): array
    {
        $literals = $this->hostIaForbiddenLiterals();
        foreach ($this->hostIaScopedLiterals() as $literal => $scope) {
            foreach ($scope as $where) {
                if ($where === $root || str_starts_with($where, rtrim($root, '/').'/')) {
                    $literals[] = $literal;
                    break;
                }
            }
        }

        return array_values(array_unique($literals));
    }

    /** Whether a file (root-prefixed, as hostIaRelative() spells it) lies inside a scoped literal's scope. */
    private function hostIaInLiteralScope(string $literal, string $relative): bool
    {
        $scope = $this->hostIaScopedLiterals()[$literal] ?? null;
        if ($scope === null) {
            return true;
        }
        foreach ($scope as $where) {
            if ($relative === $where || str_starts_with($relative, rtrim($where, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Literals measured at THIS host only, such as the flagship's rendered identifiers (app-walkthrough APP-23(b)).
     * Each one is an exact rendered string, specific enough not to match ordinary code.
     *
     * @return list<string>
     */
    protected function hostIaHostLiterals(): array
    {
        return [];
    }

    protected function assertHostIaSeamRatchet(): void
    {
        $found = $this->hostIaSeamViolations();
        $ratchet = $this->hostIaRatchet();
        ['unlisted' => $unlisted, 'stale' => $stale, 'unjudged' => $unjudged] = $this->hostIaRatchetDiff($found, $ratchet);

        $lines = [];
        foreach ($this->hostIaAbsentRoots as $root) {
            $lines[] = "  SKIP   T6 root {$root} is absent here or a published copy (not the overlay link), so its ratchet entries are not judged";
        }
        foreach ($ratchet as $id => $owner) {
            $state = isset($found[$id]) ? '  known  ' : (isset($unjudged[$id]) ? '  unjudged ' : '  STALE  ');
            $lines[] = $state."{$id}  →  {$owner}";
        }
        foreach ($unlisted as $id => $detail) {
            $lines[] = "  NEW    {$id}  ({$detail})";
        }
        fwrite(STDERR, "\nHost IA seam ratchet at ".basename(base_path()).': '.count($found).' violation(s), '
            .count($ratchet).' listed, '.count($unlisted).' unlisted, '.count($stale).' stale, '.count($unjudged)
            ." unjudged (absent root)\n".implode("\n", $lines)."\n");

        $this->assertSame([], $unlisted, 'Unlisted host-IA violations (a regression, or a ratchet entry is missing).');
        $this->assertSame([], $stale, 'Stale ratchet entries: the violation is gone, so delete the entry.');
    }

    /**
     * Compare what was found with the ratchet. An entry whose T6 root is absent at this run (a gitignored build output
     * before a build, or a js-overlay link that is off) is UNJUDGED, not stale: absence is not a fix.
     *
     * @param  array<string, string>  $found
     * @param  array<string, string>  $ratchet
     * @return array{unlisted: array<string, string>, stale: array<string, string>, unjudged: array<string, string>}
     */
    protected function hostIaRatchetDiff(array $found, array $ratchet): array
    {
        $unjudged = array_filter(
            array_diff_key($ratchet, $found),
            function (string $id): bool {
                foreach ($this->hostIaAbsentRoots as $root) {
                    foreach (['source', 'built'] as $kind) {
                        if ($id === "T6 {$kind} {$root}" || str_starts_with($id, "T6 {$kind} {$root} ") || str_starts_with($id, "T6 {$kind} {$root}/")) {
                            return true;
                        }
                    }
                }

                return false;
            },
            ARRAY_FILTER_USE_KEY,
        );

        return [
            'unlisted' => array_diff_key($found, $ratchet),
            'stale' => array_diff_key($ratchet, $found, $unjudged),
            'unjudged' => $unjudged,
        ];
    }

    /**
     * Every T1–T6 violation at this host: id => detail.
     *
     * @return array<string, string>
     */
    protected function hostIaSeamViolations(): array
    {
        $violations = [
            ...$this->hostIaT1(),
            ...$this->hostIaT2(),
            ...$this->hostIaT3(),
            ...$this->hostIaT4(),
            ...$this->hostIaT5(),
            ...$this->hostIaT6(),
        ];
        ksort($violations);

        return $violations;
    }

    /** T1: the nav port is the checked decorator, every frame manifest route is the packaged controller, and DashboardBacking reads the port. */
    protected function hostIaT1(): array
    {
        $out = [];

        $decorator = 'Splicewire\\Beam\\Ux\\Ia\\IaCheckedNavContributor';
        try {
            $resolved = get_class(app('Schemastud\\Frame\\Contracts\\FrameNavContributor'));
        } catch (Throwable $e) {
            $resolved = 'unresolvable';
        }
        if ($resolved !== $decorator) {
            $out["T1 nav-port {$resolved}"] = "FrameNavContributor resolves to {$resolved}, not {$decorator}";
        }

        $packaged = 'Schemastud\\Frame\\Http\\Controllers\\FrameManifestController';
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $name = (string) $route->getName();
            if (! str_contains($name, 'frame.manifest')) {
                continue;
            }
            $controller = ltrim((string) strtok((string) $route->getActionName(), '@'), '\\');
            if ($controller !== $packaged && ! is_subclass_of($controller, $packaged)) {
                $out["T1 manifest-route {$name} {$controller}"] = "{$name} is served by {$controller}";
            }
        }

        $backing = 'Splicewire\\Beam\\Ux\\Particle\\Backing\\DashboardBacking';
        if (class_exists($backing)) {
            $source = (string) file_get_contents((new \ReflectionClass($backing))->getFileName());
            if (str_contains($source, 'make(FrameNavContribution::class)')) {
                $out['T1 dashboard-backing-concrete'] = 'DashboardBacking resolves the concrete FrameNavContribution, not the port';
            }
        }

        return $out;
    }

    /** T2 (structural until UX-06): I1 and I4 over the nav.yml rows each rail is seeded from. */
    protected function hostIaT2(): array
    {
        $out = [];
        $bases = ['operator' => '/operator', 'user' => '/settings'];

        foreach ($this->hostIaNavRows() as $slug => $row) {
            $segment = '/'.ltrim((string) ($row['segment'] ?? ''), '/');
            $realm = (string) ($row['realm'] ?? 'site');
            $owner = $this->hostIaOwningRealm($segment, $bases);

            // I1: a row on one rail must not point into another realm's routeBase. The account rail renders
            // tenant-side rows, so for it "another realm" is operator or user.
            if ($owner !== null && $owner !== $realm && ! ($realm === 'account' && $owner === 'user')) {
                $out["T2 I1 nav.yml:{$slug}"] = "{$realm} row points into the {$owner} realm ({$segment})";
            }

            // I4: an href under a registered routeBase joins to a mounted GET route.
            if ($owner !== null && ! $this->hostIaGetRouteExists($segment)) {
                $out["T2 I4 nav.yml:{$slug}"] = "{$segment} matches no GET route";
            }
        }

        return $out;
    }

    /** T3 (structural until UX-11): one landing resolver, and no literal home path. */
    protected function hostIaT3(): array
    {
        $out = [];

        if (! class_exists('Splicewire\\Beam\\Accounts\\Landing')) {
            $out['T3 landing-resolver-missing'] = 'Splicewire\\Beam\\Accounts\\Landing does not exist';
        }

        $home = config('fortify.home');
        if (is_string($home) && str_starts_with($home, '/')) {
            $out['T3 fortify-home-literal'] = "config('fortify.home') is the literal {$home}";
        }

        return $out;
    }

    /** T4 (structural until UX-07): no route registered by a macro for a side this host does not play. */
    protected function hostIaT4(): array
    {
        $out = [];
        $plays = $this->hostIaPlays();

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            $name = (string) $route->getName();
            $side = match (true) {
                str_contains("/{$uri}", '/platform-connection') => 'client',
                str_starts_with($name, 'device.') => 'hub',
                default => null,
            };
            if ($side !== null && ! in_array($side, $plays, true)) {
                $label = $name !== '' ? $name : $uri;
                $out["T4 {$side}-route {$label}"] = "a {$side}-side route at a host that plays [".implode(',', $plays).']';
            }
        }

        return $out;
    }

    /** T5: the host-local realm classes are gone, and nav.yml holds site rows only. */
    protected function hostIaT5(): array
    {
        $out = [];

        foreach (['App\\Beam\\RealmRegistry', 'App\\Beam\\OperatorRailSeat'] as $class) {
            if (class_exists($class)) {
                $out["T5 class {$class}"] = "{$class} still exists";
            }
        }

        foreach ($this->hostIaNavRows() as $slug => $row) {
            $realm = (string) ($row['realm'] ?? 'site');
            if ($realm !== 'site') {
                $out["T5 nav.yml:{$slug} realm={$realm}"] = "nav.yml row {$slug} is in the {$realm} realm";
            }
        }

        return $out;
    }

    /** T6: no forbidden literal in source or in the built bundles. The sweep must exit 1; rc 2 fails the test. */
    protected function hostIaT6(): array
    {
        $out = [];
        $this->hostIaAbsentRoots = [];
        $roots = $this->hostIaSweepRoots();

        foreach (['source', 'built'] as $kind) {
            $paths = [];
            foreach ($roots[$kind] as $root) {
                $path = str_starts_with($root, '/') ? $root : base_path($root);
                if (file_exists($path) && $this->hostIaIsJudgedRoot($path)) {
                    $paths[realpath($path) ?: $path] = $root;
                } elseif (! in_array($root, $this->hostIaAbsentRoots, true)) {
                    $this->hostIaAbsentRoots[] = $root;
                }
            }
            if ($paths === []) {
                continue;
            }

            foreach ($paths as $real => $root) {
                $literals = $this->hostIaLiteralsFor($root);

                // Tests, stories and prototypes are not product source (app-walkthrough APP-25): they may name what the
                // product must not.
                $args = ['grep', '-rIlF', '--exclude=*.test.*', '--exclude=*.spec.*', '--exclude=*.stories.*',
                    '--exclude-dir=_prototype', '--exclude-dir=__tests__', '--exclude-dir=__fixtures__'];
                foreach ($literals as $literal) {
                    $args[] = '-e';
                    $args[] = $literal;
                }
                $process = new Process([...$args, '--', $real]);
                $process->run();

                $this->assertContains($process->getExitCode(), [0, 1], "T6 {$kind} sweep of {$root} failed (rc {$process->getExitCode()}): ".$process->getErrorOutput());

                foreach (array_filter(explode("\n", $process->getOutput())) as $file) {
                    $content = (string) file_get_contents($file);
                    foreach ($literals as $literal) {
                        if (str_contains($content, $literal) && $this->hostIaInLiteralScope($literal, $this->hostIaRelative($file, $paths))) {
                            // Built bundle names are content-hashed, so a built finding is keyed by its root.
                            $where = $kind === 'built' ? $root : $this->hostIaRelative($file, $paths);
                            $out["T6 {$kind} {$where} {$literal}"] = "{$this->hostIaRelative($file, $paths)} contains {$literal}";
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A package root under `node_modules` is judged only when it is the js-overlay LINK to a family checkout, which is
     * what the ratchet's entries were pinned from. Decided by where it RESOLVES, not by whether it is a link: under
     * pnpm a published install is a symlink too, into `node_modules/.pnpm/…`. The overlay resolves outside any
     * `node_modules` (a family checkout). A published copy (the overlay off: beam-inertia publishes `src` too) is a
     * different file set, so it is treated like an absent root: named, and its entries unjudged.
     */
    protected function hostIaIsJudgedRoot(string $path): bool
    {
        if (! str_contains($path, '/node_modules/')) {
            return true;
        }

        $resolved = realpath($path);

        return $resolved !== false && ! str_contains($resolved, '/node_modules/');
    }

    /** @return array<string, array<string, mixed>> */
    private function hostIaNavRows(): array
    {
        $file = $this->hostIaNavYml();
        if (! is_file($file)) {
            return [];
        }

        return array_filter((array) Yaml::parseFile($file), 'is_array');
    }

    /** @param array<string, string> $bases */
    private function hostIaOwningRealm(string $segment, array $bases): ?string
    {
        $owner = null;
        $longest = 0;
        foreach ($bases as $realm => $base) {
            if (($segment === $base || str_starts_with($segment, $base.'/')) && strlen($base) > $longest) {
                [$owner, $longest] = [$realm, strlen($base)];
            }
        }

        return $owner;
    }

    private function hostIaGetRouteExists(string $path): bool
    {
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $pattern = '#^/'.preg_replace('#\\\{[^}]+\\\}#', '[^/]+', preg_quote(ltrim($route->uri(), '/'), '#')).'$#';
            if (preg_match($pattern, $path === '/' ? '/' : rtrim($path, '/'))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $roots real path => configured root */
    private function hostIaRelative(string $file, array $roots): string
    {
        foreach ($roots as $real => $root) {
            if ($file === $real || str_starts_with($file, $real.'/')) {
                return $root.substr($file, strlen($real));
            }
        }

        return $file;
    }

    /** @param array<string, string> $roots real path => configured root */
    private function hostIaRootOf(string $file, array $roots): string
    {
        foreach ($roots as $real => $root) {
            if ($file === $real || str_starts_with($file, $real.'/')) {
                return $root;
            }
        }

        return $file;
    }

    // ── T7a and T8 (app-walkthrough SPEC §5.1, APP-01): closure and seat–gate parity, per realm × principal ─────────

    /**
     * The principals T7a and T8 walk, by label (`root`, `owner`, `admin`, `member`, `guest`). A host that supplies none
     * skips T7a and T8; they need the database, so a host runs them from a DB-backed test through
     * {@see assertHostIaClosureRatchet()}, beside the DB-free T1–T6 ratchet.
     *
     * @return list<string>
     */
    protected function hostIaPrincipals(): array
    {
        return [];
    }

    /** @return list<string> the realms that emit a frame manifest */
    protected function hostIaClosureRealms(): array
    {
        return ['operator', 'tenant'];
    }

    /**
     * The frame manifest (`routeContext`, `nav`, `resources`) a principal receives for a realm, or null when the realm
     * refuses them (401 or 403).
     *
     * @return array<string, mixed>|null
     */
    protected function hostIaManifestAs(string $realm, string $principal): ?array
    {
        return null;
    }

    /** The HTTP status of a resource's list GET in a realm, as a principal. */
    protected function hostIaListStatusAs(string $realm, string $principal, string $resource): int
    {
        return 0;
    }

    /**
     * The closure ratchet: known T7a/T8 violations, id => "APP-NN: why". Same contract as {@see hostIaRatchet()}.
     *
     * @return array<string, string>
     */
    protected function hostIaClosureRatchet(): array
    {
        return [];
    }

    protected function assertHostIaClosureRatchet(): void
    {
        $found = [...$this->hostIaT7(), ...$this->hostIaT8()];
        ksort($found);
        $ratchet = $this->hostIaClosureRatchet();
        $unlisted = array_diff_key($found, $ratchet);
        $stale = array_diff_key($ratchet, $found);

        $lines = [];
        foreach ($ratchet as $id => $owner) {
            $lines[] = (isset($found[$id]) ? '  known  ' : '  STALE  ')."{$id}  →  {$owner}";
        }
        foreach ($unlisted as $id => $detail) {
            $lines[] = "  NEW    {$id}  ({$detail})";
        }
        fwrite(STDERR, "\nHost IA closure ratchet (T7a, T8) at ".basename(base_path()).' over '
            .implode(', ', $this->hostIaPrincipals()).': '.count($found).' violation(s), '.count($ratchet).' listed, '
            .count($unlisted).' unlisted, '.count($stale)." stale\n".implode("\n", $lines)."\n");

        $this->assertSame([], $unlisted, 'Unlisted closure violations (a regression, or a ratchet entry is missing).');
        $this->assertSame([], $stale, 'Stale closure entries: the violation is gone, so delete the entry.');
    }

    /** @var array<string, array<string, mixed>|null> manifests by "realm principal", fetched once per run */
    private array $hostIaManifests = [];

    /** @return array<string, mixed>|null */
    private function hostIaManifest(string $realm, string $principal): ?array
    {
        $key = "{$realm} {$principal}";
        if (! array_key_exists($key, $this->hostIaManifests)) {
            $this->hostIaManifests[$key] = $this->hostIaManifestAs($realm, $principal);
        }

        return $this->hostIaManifests[$key];
    }

    /**
     * T7a, closure (server half): per realm × principal, every nav routeName names a leaf (`nav-orphan`); every
     * non-parameterised list|detail|widget leaf has a nav home (`no-home`, judged per principal and collapsed to the
     * realm when no principal seats it); no leaf or nav node has an empty label (`empty-label`). "hrefs are minted" is
     * T6's `'href' =>` over the host's navigation source. A leaf cannot yet be declared `unseated`, so every homeless
     * place is listed until APP-15/APP-16/APP-21 seat or un-emit it.
     */
    protected function hostIaT7(): array
    {
        $out = [];
        foreach ($this->hostIaClosureRealms() as $realm) {
            $homeless = [];
            $judged = 0;
            foreach ($this->hostIaPrincipals() as $principal) {
                $manifest = $this->hostIaManifest($realm, $principal);
                if ($manifest === null) {
                    continue;
                }
                $judged++;
                $leaves = $this->hostIaLeaves($manifest);
                $nodes = $this->hostIaNavNodes($manifest['nav'] ?? []);
                $seated = [];
                foreach ($nodes as $node) {
                    $routeName = $node['routeName'] ?? null;
                    if (trim((string) ($node['title'] ?? '')) === '') {
                        $out["T7a {$realm} empty-label nav ".($routeName ?? ($node['href'] ?? '?'))] = 'a nav node with no title';
                    }
                    if ($routeName === null || str_ends_with($routeName, '.section')) {
                        continue;
                    }
                    $seated[$routeName] = true;
                    if (! isset($leaves[$routeName])) {
                        $out["T7a {$realm} {$principal} nav-orphan {$routeName}"] = 'a nav routeName that names no leaf';
                    }
                }
                foreach ($leaves as $routeName => $leaf) {
                    if (in_array($leaf['mounts'] ?? null, ['list', 'detail', 'widget'], true)
                        && ! str_contains((string) ($leaf['path'] ?? ''), ':') && ! isset($seated[$routeName])) {
                        $homeless[$routeName][] = $principal;
                    }
                    $resource = $leaf['resource'] ?? null;
                    if ($resource !== null && trim((string) ($this->hostIaResourceLabel($manifest, $resource) ?? '')) === '') {
                        $out["T7a {$realm} empty-label leaf {$routeName}"] = "resource {$resource} declares no nav label";
                    }
                }
            }
            foreach ($homeless as $routeName => $principals) {
                if (count($principals) === $judged) {
                    $out["T7a {$realm} no-home {$routeName}"] = 'no principal has a nav home for this place';
                } else {
                    foreach ($principals as $principal) {
                        $out["T7a {$realm} {$principal} no-home {$routeName}"] = 'emitted to this principal with no nav home';
                    }
                }
            }
        }

        return $out;
    }

    /**
     * T8, seat–gate parity: per realm × principal, every seat the principal's nav emits answers its list GET (not 401 or
     * 403: `seat-denied`), and every list place the projection omits for them is refused (`omitted-open`). The places
     * are the realm's list leaves as the fullest manifest emits them; a principal the realm refuses outright (guest,
     * non-Root on operator) must be refused on every one.
     */
    protected function hostIaT8(): array
    {
        $out = [];
        foreach ($this->hostIaClosureRealms() as $realm) {
            $universe = [];
            foreach ($this->hostIaPrincipals() as $principal) {
                foreach ($this->hostIaLeaves($this->hostIaManifest($realm, $principal) ?? []) as $leaf) {
                    if (($leaf['mounts'] ?? null) === 'list' && ($leaf['resource'] ?? null) !== null) {
                        $universe[$leaf['resource']] = $leaf['routeName'];
                    }
                }
            }
            ksort($universe);
            foreach ($this->hostIaPrincipals() as $principal) {
                $manifest = $this->hostIaManifest($realm, $principal);
                $seatedResources = [];
                if ($manifest !== null) {
                    $leaves = $this->hostIaLeaves($manifest);
                    foreach ($this->hostIaNavNodes($manifest['nav'] ?? []) as $node) {
                        $leaf = $leaves[$node['routeName'] ?? ''] ?? null;
                        if ($leaf !== null && ($leaf['mounts'] ?? null) === 'list' && ($leaf['resource'] ?? null) !== null) {
                            $seatedResources[$leaf['resource']] = true;
                        }
                    }
                }
                foreach ($universe as $resource => $routeName) {
                    $status = $this->hostIaListStatusAs($realm, $principal, $resource);
                    $denied = in_array($status, [401, 403], true);
                    if (isset($seatedResources[$resource]) && $denied) {
                        $out["T8 {$realm} {$principal} seat-denied {$resource}"] = "{$routeName} is seated but its list GET answers {$status}";
                    } elseif (! isset($seatedResources[$resource]) && ! $denied && $status !== 404) {
                        $out["T8 {$realm} {$principal} omitted-open {$resource}"] = "{$routeName} is not seated but its list GET answers {$status}";
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> the manifest's leaves by routeName */
    private function hostIaLeaves(array $manifest): array
    {
        $leaves = [];
        foreach ($manifest['routeContext'] ?? [] as $leaf) {
            if (isset($leaf['routeName'])) {
                $leaves[$leaf['routeName']] = $leaf;
            }
        }

        return $leaves;
    }

    /** @return list<array<string, mixed>> every nav node, depth-first */
    private function hostIaNavNodes(array $nav): array
    {
        $nodes = [];
        $walk = function (array $items) use (&$walk, &$nodes): void {
            foreach ($items as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $nodes[] = $node;
                $walk($node['children'] ?? []);
            }
        };
        $walk($nav['items'] ?? (array_is_list($nav) ? $nav : []));

        return $nodes;
    }

    private function hostIaResourceLabel(array $manifest, string $resource): ?string
    {
        foreach ($manifest['resources'] ?? [] as $definition) {
            if (($definition['key'] ?? null) === $resource) {
                return $definition['nav']['label'] ?? $definition['label'] ?? null;
            }
        }

        return null;
    }
}
