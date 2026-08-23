<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use RuntimeException;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;

/**
 * Document a path parameter's TYPE, EXAMPLE and DESCRIPTION from the resource the segment names.
 *
 * The sibling of {@see ParticleListParameterStrategy} one axis over — same registry, same
 * defer-on-miss contract — and the reason it exists is the same one: Scribe's stock
 * `GetFromLaravelAPI` already knows how to derive all three FROM A MODEL, and simply cannot find
 * ours. Its fallback searches `App\Models\<Thing>` / `App\<Thing>`; every model in a dissolved
 * estate lives in a package, so every path parameter falls through to `setTypesAndExamplesForOthers()`
 * and is typed `string` and filled from faker — or, worse, from `regexify` against the route's own
 * `where` constraint, which turns a uuid pattern into `CdbAAddd-fdCB-Fcfd-EdfB-eBBAabbBCdaE`: a
 * string that LOOKS like a uuid, is not one, and defeats "Try it" precisely because it is plausible.
 *
 * ## Three rungs, declaration first
 *
 * Ticket 17's group chain shape, for the same reason: one signal does not cover the surface, and a
 * guess that DEFERS to a declaration is honest scaffolding (ticket 24) — the sin is a guess that
 * overwrites one.
 *
 *  1. **The stamp.** The route's `_particle` / `_particle_op_resource` default names the resource it
 *     serves, and {@see ParticleController::subjectId()} resolves that resource's subject from the
 *     `{id}` parameter BY NAME. So on a stamped route, `{id}` is the subject — under a standalone
 *     mount and a relative one alike — and nothing else about the URI needs reading.
 *  2. **The segment.** Any other parameter is named by the segment in front of it (`{fragment}` in
 *     `/fragments/{fragment}/media`), which is Scribe's own heuristic pointed at the particle
 *     registry instead of `App\Models\*`. It fires only on an exact registry key, so a miss is silent.
 *  3. **The constraint.** A `where` pattern that spells a uuid is itself a declaration — the one the
 *     `regexify` path currently mangles. Read for what it says; any other pattern defers untouched.
 *
 * Rung 1 covers 20 of this host's 160 documented path parameters, rung 2 another 65, rung 3 another
 * 18. Building rung 1 alone — as the ticket chartered — would have left 45 of the 51 mangled examples
 * exactly as they are, which is what the measurement was for.
 *
 * ## Which column a parameter actually resolves against
 *
 * {@see ParticleResource::$routeKey}, falling back to the model's primary key — NOT
 * `getRouteKeyName()`. That is the ticket's own instruction inverted, and the running code is why:
 * `findParticle()` branches on the DECLARED `routeKey` and otherwise calls `findOrFail($id)`, which
 * is the primary key. A particle mount never consults `getRouteKeyName()`, so documenting it would
 * publish an identifier the endpoint does not accept — the map's recurring disease (54, 23) rather
 * than a fix for it. The two agree on all 28 of this host's resources today, so this is a choice of
 * AUTHORITY, not of value; it starts mattering the moment ticket 19's slug lands.
 *
 * Examples are DERIVED FROM THE KEY, never generated: a fresh uuid per run would churn the artifact
 * on every `scribe:generate` and make a docs diff unreadable. Same resource, same example, forever.
 *
 * Returns `null` when it has nothing to say, so non-particle routes keep Scribe's own answers.
 */
class ParticleUrlParameterStrategy extends Strategy
{
    /** Crockford base32, the ULID alphabet. */
    protected const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** A `where` constraint that spells a uuid, whatever the author's preferred hex class. */
    protected const UUID_CONSTRAINT = '/^\[?[^\]]*a-f[^\]]*\]?\{8\}-/i';

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $route = $endpointData->route;

        if ($route === null) {
            return null;
        }

        $stamped = BeamRouteAction::resourceKey($route);
        $parameters = [];

        preg_match_all('/\{(.*?)\}/', $endpointData->uri, $matches);

        foreach ($matches[1] as $match) {
            $name = rtrim($match, '?');

            // Rung 1 — the subject of a stamped route, then rung 2 — the segment in front.
            $resource = ($name === ParticleController::SUBJECT ? $this->resource($stamped) : null)
                ?? $this->resource($this->segmentBefore($endpointData->uri, $name));

            if ($resource !== null) {
                $parameters[$name] = $this->fromResource($resource);

                continue;
            }

            // Rung 3 — a uuid the route declares and nothing else claims.
            if ($this->declaresUuid($route->wheres[$name] ?? null)) {
                $parameters[$name] = [
                    'type' => 'string',
                    // Seeded by the THING the segment names rather than the whole URI, so every
                    // `/cells/{id}` route shows the same cell rather than eleven different ones.
                    'example' => $this->uuid($this->segmentBefore($endpointData->uri, $name).':'.$name),
                ];
            }
        }

        return $parameters === [] ? null : $parameters;
    }

    /**
     * The registered resource a segment names, or null. Tries the segment verbatim first — every
     * registered key is plural-kebab (ticket 16) and so is almost every URI segment — then its
     * plural, for the singular segments that remain.
     */
    protected function resource(?string $segment): ?ParticleResource
    {
        if ($segment === null || $segment === '') {
            return null;
        }

        $registry = app(ParticleResourceRegistry::class);

        foreach ([$segment, Str::plural($segment)] as $key) {
            if (! $registry->has($key)) {
                continue;
            }

            try {
                return $registry->get($key);
            } catch (RuntimeException) {
                // Registered as a raw ResourceDefinition — no model, nothing to derive. Same silence
                // ParticleResourceModelResolver keeps for the same case.
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromResource(ParticleResource $resource): array
    {
        $model = $this->model($resource);
        $column = $resource->routeKey ?? $model?->getKeyName() ?? 'id';
        $kind = $this->kind($resource, $model, $column);

        return [
            'type' => $kind === 'integer' ? 'integer' : 'string',
            'example' => $this->example($kind, $resource),
            'description' => sprintf(
                'The %s of the %s.',
                $this->noun($kind),
                $this->subject($resource),
            ),
        ];
    }

    /**
     * A throwaway instance of the resource's model, purely to read its key name and key type for the
     * generated docs. Null when the backing declares no single model — a resource whose rows are pivot
     * rows or span two record types has no key shape to describe, and the caller falls back to `id`.
     */
    protected function model(ParticleResource $resource): ?Model
    {
        $class = $resource->modelClass();

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $model = new $class;

        return $model instanceof Model ? $model : null;
    }

    /**
     * What SHAPE the resolving column holds: `uuid`, `ulid`, `integer`, `slug` or plain `string`.
     *
     * A declared `routeKey` is never the primary key, so its type comes from what it is CALLED — the
     * only thing declared about it at this layer. The primary key answers for itself: an
     * auto-incrementing key is an integer, and a string key says which flavour through the trait that
     * fills it (`HasUuids`/`HasUlids`), falling back to the column name for the models that assign
     * their own (`media`, whose primary key IS `uuid`).
     */
    protected function kind(ParticleResource $resource, ?Model $model, string $column): string
    {
        if ($model !== null && $resource->routeKey === null && $model->getKeyName() === $column) {
            if ($model->getKeyType() === 'int' || $model->getKeyType() === 'integer') {
                return 'integer';
            }

            $traits = class_uses_recursive($model);

            if (isset($traits[HasUlids::class])) {
                return 'ulid';
            }

            if (isset($traits[HasUuids::class])) {
                return 'uuid';
            }
        }

        return match (true) {
            Str::contains($column, 'uuid', ignoreCase: true) => 'uuid',
            Str::contains($column, 'ulid', ignoreCase: true) => 'ulid',
            Str::contains($column, 'slug', ignoreCase: true) => 'slug',
            default => 'string',
        };
    }

    protected function example(string $kind, ParticleResource $resource): mixed
    {
        return match ($kind) {
            'integer' => 1,
            'uuid' => $this->uuid($resource->key),
            'ulid' => $this->ulid($resource->key),
            'slug' => Str::slug($this->subject($resource)),
            default => Str::slug($this->subject($resource)),
        };
    }

    protected function noun(string $kind): string
    {
        return match ($kind) {
            'uuid' => 'UUID',
            'ulid' => 'ULID',
            'integer' => 'ID',
            'slug' => 'slug',
            default => 'identifier',
        };
    }

    /**
     * What one record of this resource is called, in prose.
     *
     * The declared `singularLabel` when there is one — it exists precisely for the records whose name
     * the key cannot produce (`media`, which would singularize to "medium") — and otherwise the KEY,
     * never `label`. `label` names a SURFACE, not a record: `compositions` is labelled "Studio"
     * because that is the SPA section it lives in, and "the UUID of the studio" would be a plain lie
     * about what the parameter identifies. Ticket 16's finding from a new direction — the key is the
     * resource's own name; everything else is an exposure of it.
     */
    protected function subject(ParticleResource $resource): string
    {
        return Str::lower($resource->singularLabel ?: Str::headline(Str::singular($resource->key)));
    }

    /** The segment immediately in front of `{$parameter}`, or null when it leads the URI. */
    protected function segmentBefore(string $uri, string $parameter): ?string
    {
        $parts = explode('/', trim($uri, '/'));
        $index = array_search('{'.$parameter.'}', $parts, true);

        if ($index === false || $index === 0) {
            return null;
        }

        return $parts[$index - 1];
    }

    protected function declaresUuid(?string $constraint): bool
    {
        return is_string($constraint) && preg_match(static::UUID_CONSTRAINT, $constraint) === 1;
    }

    /** A stable, valid v4-shaped uuid for a given seed — the same one on every run. */
    protected function uuid(string $seed): string
    {
        $hex = md5($seed);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            substr('89ab', hexdec($hex[16]) % 4, 1),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }

    /** A stable, well-formed 26-character ULID for a given seed. */
    protected function ulid(string $seed): string
    {
        $hex = md5($seed.'ulid').md5($seed.'ulid2');
        $ulid = '';

        for ($i = 0; $i < 26; $i++) {
            $ulid .= static::CROCKFORD[hexdec(substr($hex, $i * 2, 2)) % 32];
        }

        return $ulid;
    }
}
