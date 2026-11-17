<?php

namespace Splicewire\Beam\Graph;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a derived graph operation refused because the resource's backing cannot serve it (graph-operations
 * 02). Each case's VALUE is the stable wire code a client matches; its English is declared on the case
 * with {@see Message} and its key is derived (`beam::enums.GraphOperationError.cases.<Case>.message`), so
 * the text is translatable and overridable like every other declared string (ADR-0244,
 * docs/agents/user-facing-text.convention.md).
 *
 * These are the store-capability guards: a {@see \Splicewire\Beam\Particle\Backing\GraphsRecords} backing
 * hands back a {@see \Rushing\Graphine\Contracts\GraphStore}, and the handler branches on
 * `GraphStore::supports()` (never a concrete driver). A store that does not reach the role an operation
 * needs — the mandatory Declare/Compute spine for neighbours/slice/path/cycles — declines it with the
 * matching case here, thrown as a {@see \Splicewire\Beam\Localization\CodedValidationException}. The
 * over-limit and roots-required refusals are the typed {@see Data\GraphRefusalData} body instead, declared
 * through the operation's `errors:`.
 */
enum GraphOperationError: string implements CodedError
{
    use IsCodedError;

    #[Message("This resource's graph store cannot read neighbourhood structure.")]
    case StoreCannotReadNeighbours = 'graph_store_cannot_read_neighbours';

    #[Message("This resource's graph store cannot compute a path.")]
    case StoreCannotComputePath = 'graph_store_cannot_compute_path';

    #[Message("This resource's graph store cannot read structure from roots.")]
    case StoreCannotReadSlice = 'graph_store_cannot_read_slice';

    #[Message("This resource's graph store cannot detect cycles.")]
    case StoreCannotDetectCycles = 'graph_store_cannot_detect_cycles';

    #[Message('The `:resource` resource does not back a graph.')]
    case ResourceDoesNotBackGraph = 'resource_does_not_back_graph';

    // The two refusals carried by {@see Data\GraphRefusalData} (the `errors:` body): their English lives
    // here too, keyed, so no user-facing sentence is an unkeyed literal (graph-operations 02 review B2).
    #[Message('The graph request exceeds the `:limit` limit: asked for :requested, the maximum is :max.')]
    case OverLimit = 'over_limit';

    #[Message("The graph slice needs `roots` to walk from: this resource's graph store cannot enumerate the whole snapshot without them.")]
    case RootsRequired = 'roots_required';
}
