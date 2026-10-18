<?php

namespace Splicewire\Beam\Read;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;

/**
 * The minimal projection declaration for a read (beam-write-pipeline ticket 13, DESIGN §9c). It is the
 * read-side peer of the write pipeline's payload+actor: a single {@see $includes} list, a cardinality
 * mode, the actor, and an optional pinned version.
 *
 * THE ONE INCLUDE LIST IS THE PAYOFF. `$includes` (dot-nested — path length carries depth) compiles to
 * BOTH read axes at once — the eager-load axis (data-filters `allowedIncludes` / `->with` / `withCount`)
 * AND the spatie serialization partial (`Data->include([...])`) — killing the double-declaration that
 * today is hand-synced in `FragmentResourceHandler::index` (eager-load list vs `->include(...)` list).
 * A {@see ParticleHydrator} is what compiles it to both.
 *
 * Deliberately NOT here (cut from v1, DESIGN §9c/§9f): sparse-fieldsets (`fields`) — data-filters has no
 * `allowedFields` reflection yet; a `depth` knob — path length already IS depth; filters/sorts — they
 * stay on the request/QueryBuilder (the context owns *projection*, never *selection*); field-level read
 * authz — an optional `ReadGate` decorator, not the base.
 */
class ReadContext
{
    /**
     * @param  list<string>  $includes  dot-nested include names (compiles to eager-load AND serialization)
     */
    public function __construct(
        public array $includes = [],
        public Cardinality $cardinality = Cardinality::One,
        public ?Authenticatable $actor = null,
        public ?int $version = null,
        public bool $publicProjection = false,
        public ?string $resource = null,
    ) {}

    /** Internal request metadata, never query input. The actor remains the actual visitor. */
    public static function fromRequest(Request $request): ?self
    {
        $context = $request->attributes->get(self::class);

        return $context instanceof self && $context->actor === $request->user() ? $context : null;
    }

    public static function projectsPublic(Request $request, ?string $resource = null): bool
    {
        $context = self::fromRequest($request);

        return $request->user() === null || ($context?->publicProjection === true
            && ($resource === null || $context->resource === $resource));
    }

    /**
     * A detail (single-record) read.
     *
     * @param  list<string>  $includes
     */
    public static function detail(array $includes = [], ?Authenticatable $actor = null, ?int $version = null): self
    {
        return new self($includes, Cardinality::One, $actor, $version);
    }

    /**
     * A list read.
     *
     * @param  list<string>  $includes
     */
    public static function list(array $includes = [], ?Authenticatable $actor = null): self
    {
        return new self($includes, Cardinality::Many, $actor);
    }
}
