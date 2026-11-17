<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Graph\GraphOperationError;

/**
 * Why a derived graph operation REFUSED — the body it answers with at 422, declared through the
 * operation's `errors:` slot (graph-operations 02, scope 4 / D5).
 *
 * Two refusals ride this one shape, told apart by `code`:
 *
 *   - `over_limit` — the request asked for more than {@see \Splicewire\Beam\Graph} allows (a `depth` past
 *     `beam.core.graph.max_depth`, a `limit` past `beam.core.graph.max_nodes`) or the scoped set was too
 *     large to answer within the asked bound. `limit` names WHICH ceiling (`depth` or `nodes`), `max` is
 *     that ceiling's value and `requested` what the request asked for, so the refusal NAMES the limit
 *     rather than failing opaquely. Nothing is truncated to fit (D5): a set that does not fit is refused.
 *   - `roots_required` — `graph` (the bounded slice) was asked WITHOUT `roots` of a backing whose store
 *     cannot enumerate the whole snapshot. Such a store answers a neighbourhood from an anchor, so it
 *     needs roots to walk from; `limit`/`max`/`requested` are null and `message` says so.
 *
 * It is a REFERENCE-only shape like every other graph wire type (D4): no row payload, just the fact of
 * the refusal and the numbers that explain it. The runtime does not read `errors:` — the handler writes
 * this body itself ({@see \Splicewire\Beam\Http\Particle\ParticleOperationController::finish()} passes an
 * already-built response through); `errors:` is what publishes the shape to the spec and the generated
 * clients so a caller hydrates a typed refusal instead of parsing the body by hand.
 */
class GraphRefusalData extends Data
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $limit = null,
        public ?int $max = null,
        public ?int $requested = null,
    ) {}

    /**
     * The request asked past a named ceiling, or the scoped set would not fit within it. The `code` and
     * the localized `message` both come from {@see GraphOperationError::OverLimit} (review B2): the
     * sentence is keyed on the enum's `#[Message]`, never an unkeyed literal in a 422 body.
     */
    public static function overLimit(string $limit, int $max, int $requested): self
    {
        return new self(
            code: GraphOperationError::OverLimit->value,
            message: GraphOperationError::OverLimit->message(['limit' => $limit, 'max' => $max, 'requested' => $requested]),
            limit: $limit,
            max: $max,
            requested: $requested,
        );
    }

    /** The slice was asked without roots of a store that cannot enumerate the whole snapshot. */
    public static function rootsRequired(): self
    {
        return new self(
            code: GraphOperationError::RootsRequired->value,
            message: GraphOperationError::RootsRequired->message(),
        );
    }
}
