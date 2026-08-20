<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * LAST-RESORT title derivation for the endpoints nothing else titled — the hand-written thin controllers
 * with no docblock summary (saved-filters, json-schemas, frame routes, beam/accounts, studio, …) whose
 * sidebar entries fell to the bare path (`GET /api/v1/saved-filters`) beside the titled endpoints.
 *
 * It derives an HONEST title from what the route itself declares, and nothing more:
 *
 *   - **Route name first** — `api.v1.saved-filters.apply` → "Apply Saved Filter". The name's last segment
 *     is the action, the one before it the resource; leading `api` / `v{n}` segments are dropped. A CRUD
 *     action maps to its verb (`index` → "List {Plural}", `show`/`store`/`update`/`destroy` →
 *     "{Verb} {Singular}"); a custom action whose first word is a known verb reads verb-first
 *     ("Update Member Role", "Execute Tenant Sync"); any other action reads noun-first
 *     ("Route Manifest", "Usage Summary") — claiming a verb the route never declared would over-promise.
 *   - **URI + method otherwise** — `GET /api/v1/saved-filters` → "List Saved Filters"; POST → create,
 *     GET-with-trailing-`{id}` → show, PUT/PATCH → update, DELETE → delete. A singular-shaped GET
 *     collection segment (`/budget`) titles "Show", not "List" — the route shows one thing.
 *   - **Nested segments read naturally** — `/circuits/{circuit}/runs` appends "for a Circuit", exactly
 *     the phrasing {@see ParticleTitleStrategy} uses for relative particle mounts.
 *
 * **Ordering / precedence (load-bearing).** Registered LAST in `strategies.metadata`, after
 * `GetFromDocBlocks` AND the declaration strategies (beam-core's `GroupStrategy`,
 * {@see ParticleTitleStrategy}) — so an explicit docblock summary and every declaration-derived title
 * always WIN. It returns `null` (defers) whenever ANY earlier strategy already set a title; it never
 * overwrites one. It emits a title only — no description, because the route declares no behaviour to
 * describe and inventing one would be dishonest.
 */
class RouteTitleStrategy extends Strategy
{
    /**
     * CRUD action names (route-name last segments AND the shapes the URI+method fallback synthesizes)
     * mapped by {@see self::compose()} to their conventional verb phrasing.
     */
    private const CRUD_ACTIONS = ['index', 'show', 'store', 'create', 'update', 'destroy', 'delete'];

    /**
     * Action first-words that read verb-first ("Apply Saved Filter", "Update Member Role"). An action
     * word NOT in this list titles noun-first ("Route Manifest", "Circuit Intake") — we can't know it's
     * a verb, and a noun-first reading never claims more than the route shows.
     */
    private const VERBS = [
        'accept', 'activate', 'apply', 'approve', 'archive', 'assign', 'attach', 'cancel', 'clear',
        'complete', 'confirm', 'connect', 'deactivate', 'detach', 'disable', 'download', 'duplicate',
        'enable', 'execute', 'export', 'generate', 'import', 'invite', 'merge', 'move', 'preview',
        'publish', 'purge', 'refresh', 'reject', 'remove', 'request', 'resend', 'reset', 'resolve',
        'restore', 'retry', 'revoke', 'run', 'search', 'send', 'submit', 'sync', 'toggle', 'upload',
        'validate', 'verify',
    ];

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        // A docblock summary or a declaration strategy already titled this endpoint: never overwrite.
        if (($endpointData->metadata->title ?? '') !== '') {
            return null;
        }

        $method = strtoupper($endpointData->httpMethods[0] ?? 'GET');
        $uriSegments = $this->uriSegments($endpointData->uri);

        $name = $endpointData->route?->getName();
        [$resource, $action] = $name !== null && $name !== ''
            ? $this->parseName($name, $method, $uriSegments)
            : $this->parseUri($uriSegments, $method);

        if ($action === null) {
            return null; // Nothing declared to derive from — an honest bare path beats a guessed title.
        }

        $title = $this->compose($resource, $action, $method, $uriSegments);

        return $title === '' ? null : ['title' => $title];
    }

    /** The URI's segments with the `api` / `v{n}` prefix dropped — they carry no title information. */
    private function uriSegments(string $uri): array
    {
        $segments = array_values(array_filter(explode('/', trim($uri, '/')), fn ($s) => $s !== ''));

        while ($segments !== [] && preg_match('/^(api|v\d+)$/i', $segments[0])) {
            array_shift($segments);
        }

        return $segments;
    }

    /**
     * [resource, action] off the route NAME — the strongest declaration a hand-written route carries.
     * The last segment is the action, the one before it the resource; deeper prefixes (`beam.accounts.`)
     * are namespace context the URI's parent detection re-supplies where it matters. A single-segment
     * name is either a bare verb (`search` — the action IS the title) or a bare noun (`me` — the
     * resource, with the CRUD action synthesized from the method + URI shape).
     */
    private function parseName(string $name, string $method, array $uriSegments): array
    {
        $segments = array_values(array_filter(explode('.', $name), fn ($s) => $s !== ''));

        while ($segments !== [] && preg_match('/^(api|v\d+)$/i', $segments[0])) {
            array_shift($segments);
        }

        if ($segments === []) {
            return [null, null];
        }

        if (count($segments) === 1) {
            $only = $segments[0];

            return in_array(Str::lower($only), self::VERBS, true)
                ? [null, $only]
                : [$only, $this->crudFromShape($only, $method, $uriSegments)];
        }

        return [$segments[count($segments) - 2], $segments[count($segments) - 1]];
    }

    /**
     * [resource, action] off the URI + method, for a nameless route. The last static segment is the
     * resource — unless a `{param}` precedes it, which marks it an item-action segment
     * (`saved-filters/{id}/apply`) whose resource is the static segment before that param.
     */
    private function parseUri(array $segments, string $method): array
    {
        $statics = array_values(array_filter($segments, fn ($s) => ! $this->isParam($s)));

        if ($statics === []) {
            return [null, null];
        }

        $last = $segments[count($segments) - 1];

        if (! $this->isParam($last) && $this->hasParamBefore($segments, count($segments) - 1)) {
            // `…/{id}/apply` — trailing static after a param is the action; the resource sits before it.
            return [count($statics) >= 2 ? $statics[count($statics) - 2] : null, $last];
        }

        $resource = $statics[count($statics) - 1];

        return [$resource, $this->crudFromShape($resource, $method, $segments)];
    }

    /**
     * The CRUD action a method + URI shape declares: trailing `{id}` targets one item; a GET collection
     * only claims "List" when the segment is plural-shaped — `GET /budget` shows one thing.
     */
    private function crudFromShape(string $resource, string $method, array $uriSegments): string
    {
        $trailingParam = $uriSegments !== [] && $this->isParam($uriSegments[count($uriSegments) - 1]);

        return match ($method) {
            'POST' => 'store',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'destroy',
            default => $trailingParam || ! $this->isPluralShaped($resource) ? 'show' : 'index',
        };
    }

    /** Compose the final title from the derived resource/action pair + the URI's nesting. */
    private function compose(?string $resource, string $action, string $method, array $uriSegments): string
    {
        // A bare verb name (`search`) IS the title — there is no resource noun to attach.
        if ($resource === null) {
            return in_array(Str::lower($action), self::VERBS, true) ? Str::headline($action) : '';
        }

        $noun = Str::headline($resource);
        [$singular, $plural] = [Str::singular($noun), Str::plural($noun)];

        $title = $this->phrase($action, $method, $singular, $plural, $uriSegments);

        $parent = $this->parentNoun($uriSegments, $singular);
        if ($parent !== null && $parent !== $singular) {
            $title .= ' for a '.$parent;
        }

        return $title;
    }

    /** The verb phrase for one action: CRUD map, verb-first custom action, or noun-first fallback. */
    private function phrase(string $action, string $method, string $singular, string $plural, array $uriSegments): string
    {
        $crud = match (Str::lower($action)) {
            'index' => "List {$plural}",
            'show' => "Show {$singular}",
            'store', 'create' => "Create {$singular}",
            'update' => "Update {$singular}",
            'destroy', 'delete' => "Delete {$singular}",
            default => null,
        };

        if ($crud !== null) {
            return $crud;
        }

        $words = preg_split('/[-_\s]+/', Str::snake($action, ' '), flags: PREG_SPLIT_NO_EMPTY);

        // A multi-word action may lead with a CRUD verb the exact-match map above didn't catch
        // (`update-role` → "Update Member Role"), so the verb check includes the CRUD verbs too.
        $verbs = array_merge(self::VERBS, ['create', 'update', 'delete', 'get', 'set', 'add', 'list', 'show']);

        if (in_array(Str::lower($words[0]), $verbs, true)) {
            // Verb-first: "Apply Saved Filter", "Update Member Role", "Preview Bills". A GET with no
            // params acts on the collection (plural); anything item-bound or mutating reads singular.
            $collection = $method === 'GET' && ! $this->hasAnyParam($uriSegments);
            $object = $collection ? $plural : $singular;
            $rest = implode(' ', array_map(Str::headline(...), array_slice($words, 1)));

            return trim(Str::headline($words[0]).' '.$object.($rest !== '' ? ' '.$rest : ''));
        }

        // Noun-first: "Route Manifest", "Usage Summary", "Circuit Intake" — the action isn't a known
        // verb, and a noun reading claims nothing the route doesn't show.
        return $singular.' '.Str::headline($action);
    }

    /**
     * The parent noun of a nested mount — `/circuits/{circuit}/runs` → "Circuit" — detected only when a
     * LATER static segment matches the resource noun (so an item-action tail like
     * `/checkout_sessions/{id}/complete` never fabricates a parent out of its own resource binding).
     */
    private function parentNoun(array $segments, string $resourceSingular): ?string
    {
        foreach ($segments as $i => $segment) {
            $next = $segments[$i + 1] ?? null;

            if ($this->isParam($segment) || $next === null || ! $this->isParam($next)) {
                continue;
            }

            foreach (array_slice($segments, $i + 2) as $later) {
                if (! $this->isParam($later) && Str::singular(Str::headline($later)) === $resourceSingular) {
                    return Str::singular(Str::headline($segment));
                }
            }
        }

        return null;
    }

    private function isParam(string $segment): bool
    {
        return str_starts_with($segment, '{');
    }

    private function hasAnyParam(array $segments): bool
    {
        return array_filter($segments, $this->isParam(...)) !== [];
    }

    private function hasParamBefore(array $segments, int $index): bool
    {
        return $this->hasAnyParam(array_slice($segments, 0, $index));
    }

    /** Is a segment's final word already plural — i.e. does the route show a collection? */
    private function isPluralShaped(string $segment): bool
    {
        $words = preg_split('/[-_\s]+/', Str::snake($segment, ' '), flags: PREG_SPLIT_NO_EMPTY);
        $lastWord = Str::lower(end($words));

        return Str::plural($lastWord) === $lastWord;
    }
}
