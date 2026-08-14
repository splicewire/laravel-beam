<?php

namespace Splicewire\Beam\Surgeon;

use Splicewire\Beam\Schema\SchemaSources;

/**
 * The boot-time audit scan-path contribution registry: a container singleton any package's
 * service provider pushes `(controllersDir, routesDir)` pairs into, so a family package's HTTP
 * surface joins the bypass/redundancy/house-style sweeps without the host app being edited —
 * the same self-registration pattern as {@see SchemaSources} et al.
 * beam-core never learns a contributor's name.
 *
 * The consuming audits ({@see ParticleControllerRedundancyAudit},
 * {@see ParticleOperationBypassAudit}, {@see HouseStyleAudit}) fold these dirs in ALONGSIDE the
 * host's own `app/Http/Controllers` + `routes/` — contributed paths extend the sweep, never
 * replace it.
 *
 * ## Honesty about reach
 * This is a BOOT-TIME seam: a package's paths are contributed by its own service provider, so
 * they join the sweep only in a host where that provider actually boots. A family package that
 * is NOT installed in the app under audit contributes nothing there — its surface is audited
 * only from hosts that compose it (or by its own package-local tooling). The seam widens
 * visibility to whatever the host composes; it is not a fleet-wide census.
 */
class AuditScanPaths
{
    /** @var list<array{package: string, controllersDir: string, routesDir: string}> registrations, in boot order */
    protected array $paths = [];

    /**
     * Contribute a package's HTTP scan surface: the controllers dir its action classes live
     * under, and the routes dir that mounts them. A dir that does not exist (a package shipping
     * controllers but no route files, or vice versa) is fine — the audits' file walks treat an
     * absent dir as empty.
     */
    public function register(string $package, string $controllersDir, string $routesDir): static
    {
        $this->paths[] = [
            'package' => $package,
            'controllersDir' => $controllersDir,
            'routesDir' => $routesDir,
        ];

        return $this;
    }

    /** @return list<array{package: string, controllersDir: string, routesDir: string}> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> the contributed controllers dirs, deduplicated, in registration order */
    public function controllersDirs(): array
    {
        return array_values(array_unique(array_column($this->paths, 'controllersDir')));
    }

    /** @return list<string> the contributed routes dirs, deduplicated, in registration order */
    public function routesDirs(): array
    {
        return array_values(array_unique(array_column($this->paths, 'routesDir')));
    }
}
