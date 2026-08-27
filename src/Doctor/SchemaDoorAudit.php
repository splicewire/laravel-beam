<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;
use Schemastud\DataSchemas\Generators\NonAbsoluteSchemaBaseUri;
use Schemastud\DataSchemas\Http\SchemaDocumentController;
use Schemastud\DataSchemas\Http\SchemaDoorMount;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Lifecycle\ChainedSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Support\SchemaAuthority;

/**
 * **A declared schema authority must actually answer** — for the artifacts a host has frozen under its
 * own `data-schemas.base_uri`, `GET <$id>` must reach the public schema door (beam-facade ticket 102).
 *
 * ## Why the predicate is a `->match()` PROBE and never the route table
 * Ticket 111 measured the mechanism that makes this check necessary, at `~/Herd/splicewire`:
 * `RouteCollection` keys by domain+method+URI, so a second registration of the same key does not lose
 * a race — it **replaces** the first outright, and the loser is *gone from the collection*. The door
 * was absent from a fully booted host, `getByName('data-schemas.document')` returned `false`, and
 * `route:list` printed the host as clean because it prints one row per *surviving* route. So the
 * general form of the defect ("two registrations of one key") is not observable after boot at all, and
 * the only exact, mechanically-decidable check is the door-specific one this class asks:
 *
 * > this host declares an authority ⇒ `getRoutes()->match(Request::create(<a frozen $id>))` must
 * > resolve to `data-schemas.document`.
 *
 * It compares two **independently derived** values, which is what makes it worth running:
 * {@see SchemaDocumentController} reconstructs the `$id` from the incoming
 * request and never reads `base_uri`, while minting reads `base_uri` and never sees a request. Had the
 * door reconstructed from config, this would be config checked against itself.
 *
 * ## Two findings, because they are two repairs
 * - **The door does not answer** (Warn) — a live, unreachable `$id`. Every `$ref`-following client and
 *   every validator that dereferences gets whatever else claimed that route. The repair is upstream, in
 *   the mount.
 * - **An artifact under a FOREIGN authority** (Warn) — unreachable *by construction*, since this host
 *   only ever serves its own declared origin. Not urgent, and usually not a misconfiguration at all: the
 *   registry is **write-once**, so a re-stem is additive and leaves the superseded copy sitting beside
 *   its twin (ticket 64 re-registered without deleting; 8 such copies stood at `~/Herd/splicewire-app`
 *   when this audit was written). Reported as *superseded* when a twin exists under the declared
 *   authority — the repair is deleting the old file — and as *orphaned* when none does, which is the
 *   worse state: the artifact is only reachable from an origin this host does not claim.
 *
 * ## `base_uri` is a TRI-STATE and two of its states owe nothing
 * `false` is an **opt-out**, and unset is undecided; both are conformant and must not be flagged, or
 * this check would report four false positives on the estate the day it shipped (ticket 83 declared
 * three explicit opt-outs and found a fourth standing). A non-absolute string is no longer a fourth
 * state — ticket 112 made it throw at mint time ({@see NonAbsoluteSchemaBaseUri})
 * and mount nothing — so it is reported as the error it is.
 *
 * The one case where an opt-out is still worth a word: a host declaring `false` that has **frozen
 * artifacts anyway**. Nothing serves them, and the opt-out and the store disagree about whether this
 * host has a public identity. Measured across all 15 declaring roots on 2026-08-26: the population of
 * that finding is zero, which is why it can ship as a Warn rather than as noise.
 *
 * ## Enumerate ONCE
 * `ids()` is a full directory scan — glob plus `json_decode` of every artifact, uncached — so the
 * enumeration happens exactly once per run and everything else is answered from it. That is also why
 * the served store is described from the tiers `ids()` already resolved ({@see describeRegistry()})
 * rather than by invoking the chain's factories: {@see FilesystemSchemaRegistry}'s constructor `mkdir`s
 * its directory, so describing a lazy tier eagerly would *create* the very directory whose absence is
 * worth knowing about.
 *
 * ## Why it is a new instrument, and not a widening
 * Ticket 102 asked. `DanglingPathRepoAudit` (surgeon) answers a composer-repository question and shares
 * only the word "unresolvable". {@see IntakeDoorAudit} asks structurally the same question one door
 * over — *can a mounted door answer?* — but its population is `beam.core.intake.slugs` against
 * the general {@see SchemaRegistry}, its door is a `POST` beam mounts
 * itself, and its skip condition is that door being unmounted. This one's population is the served
 * registry against the `ServedSchemaRegistry` (a deliberately different container key), its door is
 * data-schemas', and its skip condition is a `base_uri` tri-state. Folding them would put two
 * unrelated skip conditions behind one check key.
 *
 * Advisory (ticket 88: the estate's checks are advisory by construction, 1 of ~30 gates). It ships in
 * beam rather than in `schemastud/laravel-data-schemas` because the audit vocabulary is
 * `rushing/laravel-doctor` and the aggregation point is {@see BeamDoctorManifest} — data-schemas is
 * *below* beam, so an audit there would have to depend upward. Jurisdiction was measured rather than
 * assumed: of the 15 `~/Herd/*` roots carrying `config/data-schemas.php`, 14 install beam, and the one
 * that does not (`fable-legacy`) declares `false` and owes no door — so for the first time in this
 * map's history (19's shape, fired three times) the check reaches every root that owes it anything.
 */
class SchemaDoorAudit implements DoctorAudit
{
    public const CHECK = 'data-schemas.door';

    /** The name {@see LaravelDataSchemasServiceProvider::mountSchemaDoor()} mounts the door under. */
    public const ROUTE = 'data-schemas.document';

    public function __construct(protected Container $app) {}

    public static function forApp(?Container $app = null): self
    {
        return new self($app ?? \Illuminate\Container\Container::getInstance());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        // No `class_exists(SchemaDoorMount::class)` / `interface_exists(ServedSchemaRegistry::class)`
        // guard. Beam hard-requires `schemastud/laravel-data-schemas` in `require`, so the branch could
        // not execute — and here it was dead in a stronger sense than at its sibling
        // {@see \Splicewire\Beam\Surgeon\SchemaProjectionDriftAudit}: this file `use`s nine
        // `Schemastud\DataSchemas\*` classes and type-hints them BELOW the guard, so a genuinely absent
        // package would fatal on autoload whatever the branch returned. It was not a safety net, it was
        // a note claiming one — and its PASS message was the only in-repo assertion left that base beam
        // is schema-agnostic. It is not (ADR-0213, superseding that clause of ADR-0082).
        $base = $this->config('data-schemas.base_uri');

        if ($base === false || $base === null || $base === '') {
            return [$this->undeclared($base)];
        }

        if (! is_string($base) || ! SchemaAuthority::isAbsolute($base)) {
            return [Finding::warn(self::CHECK, sprintf(
                'data-schemas.base_uri is [%s], which names no origin. Nothing mounts (SchemaDoorMount '.
                'refuses a non-absolute authority) and minting a versioned $id throws, so this host can '.
                'neither serve nor freeze one. Declare an absolute https:// authority, or false to opt out.',
                is_string($base) ? $base : get_debug_type($base),
            ))];
        }

        $registry = $this->registry();

        if ($registry === null) {
            return [Finding::warn(self::CHECK, sprintf(
                'This host declares the authority %s, and nothing is bound to %s — so the door has no '.
                'registry to answer from and every $id minted under that authority 404s.',
                $base,
                ServedSchemaRegistry::class,
            ))];
        }

        $ids = $this->ids($registry);
        $location = $this->describeRegistry($registry);

        $prefix = rtrim($base, '/').'/';
        $own = array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, $prefix)));
        $foreign = array_values(array_filter($ids, static fn (string $id): bool => ! str_starts_with($id, $prefix)));

        $findings = array_merge(
            $this->doorFindings($base, $own, $location),
            $this->foreignFindings($base, $own, $foreign, $prefix),
        );

        if ($findings !== []) {
            return $findings;
        }

        // Two different Pass lines, because they rest on two different amounts of evidence. With a
        // frozen artifact the door was actually PROBED; with none there was no $id to probe it with and
        // all that could be checked is that the route is filed where the mount says it should be.
        if ($own === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'This host declares %s and mounts the door at [%s], and has frozen no artifacts under it '.
                'yet (%s). Nothing was probed: with no $id there is nothing to ask the door for, so this '.
                'line rests on the route table alone.',
                $base,
                $this->mountedKey() ?? 'no route',
                $location,
            ))];
        }

        return [Finding::pass(self::CHECK, sprintf(
            'This host declares %s and answers there: %d frozen artifact(s) (%s), every one under the '.
            'declared authority, and GET <$id> resolves to %s. Not covered here: whether the '.
            'authority resolves in DNS — the probe is this host\'s own route table, so a door that '.
            'answers locally still needs the origin pointed at it.',
            $base,
            count($own),
            $location,
            self::ROUTE,
        ))];
    }

    /**
     * The two states that owe no door. `false` is a declared opt-out and unset is undecided — both are
     * conformant, so the only thing worth saying is when the store disagrees with them.
     */
    protected function undeclared(mixed $base): Finding
    {
        $state = $base === false ? 'opts out (data-schemas.base_uri => false)' : 'declares no data-schemas.base_uri';

        $registry = $this->registry();
        $ids = $registry === null ? [] : $this->ids($registry);

        if ($ids === []) {
            return Finding::pass(self::CHECK, sprintf(
                'This host %s and has frozen no artifacts — conformant: a host that mints no versioned '.
                '$id owes no public schema door.',
                $state,
            ));
        }

        return Finding::warn(self::CHECK, sprintf(
            'This host %s, yet %d artifact(s) are frozen in its served store (%s), the first being [%s]. '.
            'No door mounts, so each of those $ids is unreachable at the origin it names. Either declare '.
            'the authority those artifacts were minted under, or delete them.',
            $state,
            count($ids),
            $registry === null ? 'no served registry bound' : $this->describeRegistry($registry),
            $ids[0],
        ));
    }

    /**
     * The probe. With a frozen artifact this asks the door's own question — does `GET <$id>` reach the
     * door — through `RouteCollection::match()`. With none, there is nothing to ask it *with*, so it
     * falls back to the route table, comparing `getDomain().'|'.uri()` rather than `uri()` alone: a
     * path-less authority mounts domain-constrained (ticket 111) and a uri-only comparison misreads
     * exactly the one shape whose door has ever actually been broken.
     *
     * @param  array<int, string>  $own
     * @return list<Finding>
     */
    protected function doorFindings(string $base, array $own, string $location): array
    {
        $expected = SchemaDoorMount::patternFor($base);
        $expectedDomain = SchemaDoorMount::domainFor($base);
        $expectedKey = ($expectedDomain ?? '-').'|'.$expected;

        if ($own === []) {
            $mounted = $this->mountedKey();

            if ($mounted === $expectedKey) {
                return [];
            }

            return [Finding::warn(self::CHECK, sprintf(
                'This host declares %s and no route named %s is mounted as [%s] — the route table has '.
                '[%s]. Nothing is frozen under the authority yet, so this is the cheap window: an $id '.
                'minted now would be unreachable the moment it is written. Note a collision leaves no '.
                'trace — a same-key registration REPLACES this door rather than losing to it.',
                $base,
                self::ROUTE,
                $expectedKey,
                $mounted ?? 'no such route',
            ))];
        }

        foreach ($this->probeIds($own) as $id) {
            $request = Request::create($id);
            $url = $request->url();

            if ($url !== $id) {
                return [Finding::warn(self::CHECK, sprintf(
                    'The frozen $id [%s] does not survive a round trip through a request URL — it comes back '.
                    'as [%s]. The door looks its artifact up by the URL as asked for, so the registry misses '.
                    'and the $id 404s even with the route mounted.',
                    $id,
                    $url,
                ))];
            }

            $matched = $this->match($request);

            if ($matched !== self::ROUTE) {
                return [Finding::warn(self::CHECK, sprintf(
                    'GET %s does NOT reach the schema door: it matches [%s], not %s. The door was expected at '.
                    '[%s]. This is a live unreachable $id — the artifact is frozen and write-once, so the '.
                    'repair is the mount, never the identity. Route-table listings will not show this: a '.
                    'colliding registration replaces the door outright and the loser is gone from the '.
                    'collection (beam-facade ticket 111).',
                    $id,
                    $matched ?? 'nothing (404)',
                    self::ROUTE,
                    $expectedKey,
                ))];
            }
        }

        return [];
    }

    /**
     * The ids to probe: the first, and the DEEPEST — the one with the most path segments.
     *
     * ## Why depth, and why this is not one probe made arbitrarily fussier (beam-facade 148)
     *
     * The failure this guards against is **depth-dependent**, and a single probe cannot see it. A
     * Laravel route parameter matches one segment unless constrained, so `schemas/{path}` without
     * `->where('path', '.*')` serves `/schemas/splice/1` and 404s
     * `/schemas/content-schema/food-safety/food-code-compliance-intake/1` — and `ids()` is sorted, so
     * which of those the old single probe happened to pick was alphabetical luck.
     *
     * 148 was filed believing exactly that constraint was missing, on 404s measured at the flagship.
     * It has in fact been on the mount since ticket 82, and the 404s were an ORIGIN mismatch (the `$id`
     * is the request URL, so a `.test` probe against a `.com` authority correctly serves nothing). The
     * premise was wrong; the gap in the instrument was real, and this closes it — a constraint that
     * narrowed to, say, two segments would have passed the old probe on every host in the estate.
     *
     * Deliberately two probes and not all of them: the population is up to 37 artifacts at the
     * flagship, `match()` walks the route collection each time, and depth is the only axis on which
     * the door's routing can differ between one artifact and another. Both probes are usually the same
     * id, which costs nothing.
     *
     * @param  array<int, string>  $own
     * @return list<string>
     */
    protected function probeIds(array $own): array
    {
        $deepest = $own[0];

        foreach ($own as $id) {
            if (substr_count($id, '/') > substr_count($deepest, '/')) {
                $deepest = $id;
            }
        }

        return $deepest === $own[0] ? [$own[0]] : [$own[0], $deepest];
    }

    /**
     * Artifacts in this host's own served store that name a DIFFERENT authority. Unreachable here by
     * construction — the door serves one origin — and split by whether a twin exists under the declared
     * authority, because that is the difference between "delete the old file" and "this shape is only
     * addressable somewhere this host does not claim".
     *
     * The twin test is a SUFFIX match against the declared-authority tail, not a path comparison, so it
     * holds whether or not the foreign authority carried a base path of its own.
     *
     * @param  array<int, string>  $own
     * @param  array<int, string>  $foreign
     * @return list<Finding>
     */
    protected function foreignFindings(string $base, array $own, array $foreign, string $prefix): array
    {
        if ($foreign === []) {
            return [];
        }

        $tails = array_map(static fn (string $id): string => substr($id, strlen($prefix)), $own);

        $superseded = [];
        $orphaned = [];

        foreach ($foreign as $id) {
            $hasTwin = false;

            foreach ($tails as $tail) {
                if ($tail !== '' && str_ends_with($id, '/'.$tail)) {
                    $hasTwin = true;

                    break;
                }
            }

            $hasTwin ? $superseded[] = $id : $orphaned[] = $id;
        }

        $findings = [];

        if ($superseded !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d artifact(s) in this host\'s served store are SUPERSEDED copies under a foreign '.
                'authority (%s), each with a twin already frozen under %s — e.g. [%s]. The registry is '.
                'write-once, so a re-stem is additive: re-registering under a new authority leaves the '.
                'old file beside the new one. They are unreachable rather than wrong, and the repair is '.
                'deleting them.',
                count($superseded),
                implode(', ', $this->authoritiesOf($superseded)),
                $base,
                $superseded[0],
            ));
        }

        if ($orphaned !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d artifact(s) in this host\'s served store name a foreign authority (%s) with NO twin '.
                'under %s — e.g. [%s]. This door only ever serves its own declared origin, so those $ids '.
                'are unreachable from here and this host is the only place they exist. Re-register the '.
                'shape under the declared authority before deleting anything: an $id is write-once, so '.
                'a re-stem orphans the old one rather than moving it.',
                count($orphaned),
                implode(', ', $this->authoritiesOf($orphaned)),
                $base,
                $orphaned[0],
            ));
        }

        return $findings;
    }

    /**
     * The distinct origins a set of `$id`s names, for the detail line.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    protected function authoritiesOf(array $ids): array
    {
        $authorities = [];

        foreach ($ids as $id) {
            $parts = parse_url($id);
            $authorities[] = ($parts['scheme'] ?? '?').'://'.($parts['host'] ?? '?');
        }

        return array_values(array_unique($authorities));
    }

    /** The name of the route a request matches, or null when nothing does. */
    protected function match(Request $request): ?string
    {
        try {
            $router = $this->app->make('router');

            if (! $router instanceof Router) {
                return null;
            }

            return $router->getRoutes()->match($request)->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How the door is actually filed in the route collection, `domain|uri`, or null when it is not
     * there at all — which after a collision is indistinguishable from never having been registered.
     */
    protected function mountedKey(): ?string
    {
        try {
            $router = $this->app->make('router');

            if (! $router instanceof Router) {
                return null;
            }

            $route = $router->getRoutes()->getByName(self::ROUTE);

            return $route === null ? null : ($route->getDomain() ?? '-').'|'.$route->uri();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function registry(): ?object
    {
        try {
            $registry = $this->app->make(ServedSchemaRegistry::class);
        } catch (\Throwable) {
            return null;
        }

        return $registry instanceof ServedSchemaRegistry ? $registry : null;
    }

    /**
     * Every `$id` the served registry can enumerate, or `[]` when it cannot answer at all — a store that
     * throws is itself a door that cannot answer, and must not take the doctor run down with it.
     *
     * @return array<int, string>
     */
    protected function ids(object $registry): array
    {
        try {
            $ids = $registry->ids();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(is_array($ids) ? $ids : [], 'is_string'));
    }

    /**
     * Where the served store actually reads from — the resolved instance's own state, never the config
     * key meant to set it (ticket 48: at five of six hosts those disagreed, silently, and the tier
     * pointed at a gitignored directory where registering an artifact commits nothing).
     *
     * Only tiers `ids()` has ALREADY resolved are described. Constructing an unresolved tier would
     * `mkdir` its directory, and a check that creates the thing it is inspecting cannot report on it.
     */
    protected function describeRegistry(object $registry): string
    {
        if ($registry instanceof FilesystemSchemaRegistry) {
            $directory = $this->property($registry, 'directory');

            return is_string($directory) ? 'filesystem tier at '.$directory : FilesystemSchemaRegistry::class;
        }

        if ($registry instanceof ChainedSchemaRegistry) {
            $resolved = $this->property($registry, 'resolved');

            if (! is_array($resolved) || $resolved === []) {
                return ChainedSchemaRegistry::class.' (no tier resolved)';
            }

            $tiers = [];

            foreach ($resolved as $tier) {
                $tiers[] = is_object($tier) ? $this->describeRegistry($tier) : 'unresolved tier';
            }

            return 'served chain, first-hit-wins — '.implode('; ', $tiers);
        }

        return $registry::class;
    }

    protected function property(object $object, string $name): mixed
    {
        try {
            $property = new \ReflectionProperty($object, $name);
            $property->setAccessible(true);

            return $property->getValue($object);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        try {
            return $this->app->make('config')->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
