<?php

namespace Splicewire\Beam\Ia;

use Closure;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * ux-walkthrough M2: the host's IA, projected. A host declares its realm profile once ({@see RealmProfiles}); this
 * module answers what both renderers draw: which realms a principal may cross to and where each one's home is, which
 * realm a path is in, and where Back leads. It wraps {@see RealmManifestProjector}, so the gating is the projector's.
 *
 * {@see plays()} and {@see serve()} are IA-6 (UX-07): a host mounts only the cross-instance sides it plays. Landing lives in beam-accounts (`Landing::for()`, UX-11) and reads only `home()`.
 */
final class HostIa
{
    /** The workspace realm: Back leads here from every other app realm (IA-4). */
    public const WORKSPACE = 'tenant';

    public function __construct(
        private readonly RealmManifestProjector $projector,
        private readonly RealmRegistry $registry,
        private readonly RealmProfiles $profiles,
    ) {}

    /**
     * Whether this host plays `$side` (IA-6). `beam.core.ia.plays` lists the sides; undeclared (null) plays every side,
     * so a host that has not declared is unchanged.
     */
    public function plays(Side $side): bool
    {
        $plays = config('beam.core.ia.plays');

        return $plays === null || in_array($side->value, (array) $plays, true);
    }

    /**
     * Mount a cross-instance surface: register `$routes`, each tagged with the side it serves (the route action's
     * `side`, which the host IA seam's T4 reads), or throw {@see SideRefused} when this host does not play that side.
     * Every D5′ macro for a cross-instance surface, and a host's own inline mount of one, calls this.
     */
    public function serve(Side $side, string $surface, Closure $routes): void
    {
        if (! $this->plays($side)) {
            throw SideRefused::for($side, $surface, array_values((array) config('beam.core.ia.plays', [])));
        }

        Route::group(['side' => $side->value], $routes);
    }

    /**
     * The switcher entries for a principal, the realm `$path` is in, and its Back target.
     *
     * A realm whose home route is not mounted offers no door, so it is left out: a closed door is absent, not broken.
     * `current` is the realm with the longest `routeBase` prefix of the path. Realms sharing a `routeBase` (the kit's
     * `tenant` and `site` are both `/`) are told apart by the longest home-route prefix; if that still ties, `current`
     * is null rather than a guess (lead default 2026-10-05, pending the integrator).
     */
    public function realms(mixed $principal, ?string $path = null): HostRealmsData
    {
        $rows = [];
        $bases = [];

        foreach ($this->projector->project($principal) as $descriptor) {
            $key = (string) $descriptor['key'];
            $profile = $this->profiles->for($key);
            $href = $this->mint($profile['home']);

            if ($href === null) {
                continue;
            }

            $rows[$key] = new HostRealmData(
                key: $key,
                label: $profile['label'],
                href: $href,
                surface: $profile['surface'],
                locked: (bool) ($descriptor['locked'] ?? false),
                upsell: $descriptor['upsell'] ?? null,
            );
            $bases[$key] = (string) $descriptor['routeBase'];
        }

        $current = $path === null ? null : $this->currentFor($path, $bases, $rows);
        $workspace = $rows[self::WORKSPACE] ?? null;
        $back = $current !== null && $current !== self::WORKSPACE && $workspace !== null && $rows[$current]->surface === 'app'
            ? new HostRealmBackData($workspace->label, $workspace->href)
            : null;

        return new HostRealmsData(array_values($rows), $current, $back);
    }

    /** A realm's home href: its profile's route name, minted with the host's prefix. */
    public function home(string $realm): string
    {
        if (! $this->registry->has($realm)) {
            throw new InvalidArgumentException("[{$realm}] is not a registered realm.");
        }

        $home = $this->profiles->for($realm)['home'];

        return $this->mint($home)
            ?? throw new InvalidArgumentException("The [{$realm}] realm's home route [{$home}] is not mounted at this host.");
    }

    private function mint(?string $routeName): ?string
    {
        return $routeName !== null && Route::has($routeName) ? route($routeName, [], false) : null;
    }

    /**
     * @param  array<string, string>  $bases  realm key → routeBase
     * @param  array<string, HostRealmData>  $rows
     */
    private function currentFor(string $path, array $bases, array $rows): ?string
    {
        $path = '/'.ltrim($path, '/');

        $byBase = $this->longest($path, $bases);
        if (count($byBase) === 1) {
            return $byBase[0];
        }

        $homes = [];
        foreach ($byBase as $key) {
            $homes[$key] = (string) parse_url($rows[$key]->href, PHP_URL_PATH);
        }
        $byHome = $this->longest($path, $homes);

        return count($byHome) === 1 ? $byHome[0] : null;
    }

    /**
     * The keys whose prefix is the longest that contains `$path`.
     *
     * @param  array<string, string>  $prefixes
     * @return list<string>
     */
    private function longest(string $path, array $prefixes): array
    {
        $best = -1;
        $keys = [];

        foreach ($prefixes as $key => $prefix) {
            $prefix = '/'.trim($prefix, '/');
            $under = $prefix === '/' || $path === $prefix || str_starts_with($path, $prefix.'/');

            if (! $under) {
                continue;
            }

            $length = strlen($prefix);
            if ($length > $best) {
                [$best, $keys] = [$length, [$key]];
            } elseif ($length === $best) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
