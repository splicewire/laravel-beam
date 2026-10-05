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
     * Roots T6 sweeps, relative to the host's base path: source roots first, then built bundles. A root that does not
     * exist is skipped and named, so a sweep cannot pass by reading nothing.
     *
     * @return array{source: list<string>, built: list<string>}
     */
    protected function hostIaSweepRoots(): array
    {
        return [
            'source' => ['app', 'resources/js', 'ui/src', '.env.example', 'node_modules/@splicewire/beam-inertia/src'],
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
        ];
    }

    protected function assertHostIaSeamRatchet(): void
    {
        $found = $this->hostIaSeamViolations();
        $ratchet = $this->hostIaRatchet();

        $unlisted = array_diff_key($found, $ratchet);
        $stale = array_diff_key($ratchet, $found);

        $lines = [];
        foreach ($ratchet as $id => $owner) {
            $lines[] = (isset($found[$id]) ? '  known  ' : '  STALE  ')."{$id}  →  {$owner}";
        }
        foreach ($unlisted as $id => $detail) {
            $lines[] = "  NEW    {$id}  ({$detail})";
        }
        fwrite(STDERR, "\nHost IA seam ratchet at ".basename(base_path()).': '.count($found).' violation(s), '
            .count($ratchet).' listed, '.count($unlisted).' unlisted, '.count($stale)." stale\n".implode("\n", $lines)."\n");

        $this->assertSame([], $unlisted, 'Unlisted host-IA violations (a regression, or a ratchet entry is missing).');
        $this->assertSame([], $stale, 'Stale ratchet entries: the violation is gone, so delete the entry.');
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
        $roots = $this->hostIaSweepRoots();

        foreach (['source', 'built'] as $kind) {
            $paths = [];
            foreach ($roots[$kind] as $root) {
                $path = base_path($root);
                if (file_exists($path)) {
                    $paths[realpath($path) ?: $path] = $root;
                }
            }
            if ($paths === []) {
                continue;
            }

            $args = ['grep', '-rIlF'];
            foreach ($this->hostIaForbiddenLiterals() as $literal) {
                $args[] = '-e';
                $args[] = $literal;
            }
            $process = new Process([...$args, '--', ...array_keys($paths)]);
            $process->run();

            $this->assertContains($process->getExitCode(), [0, 1], "T6 {$kind} sweep failed (rc {$process->getExitCode()}): ".$process->getErrorOutput());

            foreach (array_filter(explode("\n", $process->getOutput())) as $file) {
                $content = (string) file_get_contents($file);
                foreach ($this->hostIaForbiddenLiterals() as $literal) {
                    if (str_contains($content, $literal)) {
                        // Built bundle names are content-hashed, so a built finding is keyed by its root.
                        $where = $kind === 'built' ? $this->hostIaRootOf($file, $paths) : $this->hostIaRelative($file, $paths);
                        $out["T6 {$kind} {$where} {$literal}"] = "{$this->hostIaRelative($file, $paths)} contains {$literal}";
                    }
                }
            }
        }

        return $out;
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
}
