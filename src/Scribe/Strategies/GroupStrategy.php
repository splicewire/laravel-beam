<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Surface\GroupRegistry;

/**
 * Apply the host's declared taxonomy — {@see GroupRegistry} — as the Scribe `@group` and group description
 * for every documented route (api-surface-coherence ticket 17).
 *
 * This is ONE strategy where there were two, and the two it replaces were the same mistake twice:
 * `ApiGroupStrategy` matched a host glob map against the URI, and `ParticleGroupStrategy` headlined the
 * first meaningful URI segment. Both string-parsed a URL for a fact the route already carried — its
 * resource key, stamped at mount time by `Route::particleResource()`/`particleOp()` or declared by
 * `->beam()->inResource()`. The registry's chain reads the declaration first and keeps the globs as an
 * explicitly-shrinking backlog behind it.
 *
 * ## Precedence, and why it is split
 *
 * Registered LAST in `strategies.metadata`. A group resolved from a DECLARATION (registry rungs 1–3) is
 * authoritative and overwrites whatever `GetFromDocBlocks` produced: grouping is a host presentation
 * concern, and a `@group` written into a package controller encodes one host's taxonomy into code that
 * ships to every host — which is how three commerce controllers documented themselves under "Studio" long
 * after their URIs moved off that tier.
 *
 * A group resolved from the rung-4 GUESS is not authoritative, and defers to any real `@group`. The guess
 * has no more standing than the docblock; pretending otherwise would relocate the defect this ticket
 * removed.
 */
class GroupStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        // Scribe constructs strategies itself (`new $strategyClass($this->config)`), so the registry is
        // resolved here rather than injected — the same container reach every other particle strategy makes.
        $registry = app(GroupRegistry::class);

        $route = $endpointData->route;
        $uri = $endpointData->uri ?? '';

        $group = $registry->resolveRoute(
            $route !== null ? BeamRouteAction::resourceKey($route) : null,
            $uri,
        );

        if (! $registry->isDeclared($group)) {
            // Rung 4 — a guess. An explicit @group outranks it.
            $default = config('scribe.groups.default', '');

            if (($endpointData->metadata->groupName ?? $default) !== $default) {
                return null;
            }

            return ['groupName' => $group->name];
        }

        return [
            'groupName' => $group->name,
            'groupDescription' => $group->description,
        ];
    }
}
