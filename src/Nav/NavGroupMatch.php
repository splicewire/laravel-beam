<?php

namespace Splicewire\Beam\Nav;

/**
 * Which children a nav group takes, from its declared `match` (analytics-area 22; root ruling f46b2b53, 2026-11-17).
 *
 * The ONE reading of a group's `match`, so every fold that draws groups (beam-ux's collector, a host's own) takes the
 * same children:
 *
 * - **Realm-relative.** `match` is resolved against the realm's `routeBase`, so a package seat declared once for the
 *   operator realm (`/operator`) and the tenant realm (`/`) groups the same pages in both. On a root-based realm the
 *   resolved stem is just `'/'.match`, which is what the flagship's host groups have always meant.
 * - **A path prefix.** `knowledge/graph` takes `/knowledge/graph` itself and every path beneath `/knowledge/graph/`, and
 *   never `/knowledge/graphs`.
 * - **A raw prefix, with a trailing `*`.** `analytics-traffic-*` takes every href that STARTS WITH
 *   `/analytics-traffic-` (the trend, top pages and referrers panels) and not `/analytics-traffic` (the Traffic area's
 *   own page). It mirrors a nav link's own `match` glob. A `*` anywhere else is refused where the group is declared
 *   ({@see NavSection}).
 *
 * @api
 */
final class NavGroupMatch
{
    private function __construct(
        /** The resolved, absolute stem: `/operator/knowledge/graph`, or the raw prefix `/operator/analytics-traffic-`. */
        public readonly string $stem,
        /** Whether the stem is a raw string prefix (a trailing `*`) rather than a path. */
        public readonly bool $raw,
    ) {}

    /** Whether `$match` is a well-formed group match: non-empty once a trailing `*` and slashes are set aside, with no other `*`. */
    public static function isWellFormed(string $match): bool
    {
        $body = str_ends_with($match, '*') ? substr($match, 0, -1) : $match;

        return trim($body, " /") !== '' && ! str_contains($body, '*');
    }

    /** `$match` resolved against a realm's `routeBase` (`/`, `/operator`, …). */
    public static function resolve(string $match, string $routeBase): self
    {
        if (! self::isWellFormed($match)) {
            throw new \InvalidArgumentException("A nav group match must be a path or a raw prefix ending in one '*': [{$match}].");
        }
        $base = rtrim($routeBase, '/');

        if (str_ends_with($match, '*')) {
            // The raw prefix keeps everything up to the `*`, a trailing `-` or `/` included: that character is the point.
            return new self($base.'/'.ltrim(substr($match, 0, -1), '/'), true);
        }

        return new self($base.'/'.trim($match, '/'), false);
    }

    /** Whether this group takes the child at `$href`. */
    public function takes(string $href): bool
    {
        if ($this->raw) {
            return str_starts_with($href, $this->stem);
        }

        return $href === $this->stem || str_starts_with($href, $this->stem.'/');
    }

    /** The synthesized group tab's own nav-link `match` glob: it is active while any member is. */
    public function linkMatch(): string
    {
        return trim($this->stem, '/').'*';
    }

    /**
     * The synthesized group tab's `routeName`: dotted from the stem and ending in `.section`, so it reads as a group
     * HEADER that binds to no leaf (its members carry their own gates). A raw stem's trailing separator is dropped:
     * `/operator/analytics-traffic-` names `operator.analytics-traffic.section`.
     */
    public function routeName(): string
    {
        return str_replace('/', '.', trim(rtrim($this->stem, '-_./'), '/')).'.section';
    }
}
