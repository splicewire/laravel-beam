<?php

declare(strict_types=1);

namespace Splicewire\Beam\Read;

use Illuminate\Contracts\Auth\Authenticatable;
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
final class ReadContext
{
    /**
     * @param  list<string>  $includes  dot-nested include names (compiles to eager-load AND serialization)
     */
    public function __construct(
        public readonly array $includes = [],
        public readonly Cardinality $cardinality = Cardinality::One,
        public readonly ?Authenticatable $actor = null,
        public readonly ?int $version = null,
    ) {}

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
