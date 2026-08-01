<?php

namespace Splicewire\Beam\Read;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Source\Contracts\ParticleSourceResolver;

/**
 * The router bound as the {@see ParticleHydrator} port (ADR-0161 §Decisions.1, ticket 02). It does NOT
 * prefix-sniff: it asks the {@see ParticleSourceResolver} for the source authority of a ref and delegates
 * to a per-kind arm. A LOCAL ref (or an already-loaded model/payload) routes to the local hydrator arm; a
 * FOREIGN ref (conduit/federated) routes to the federated arm — which fills the `hydrate(string)` socket
 * the degenerate local reader ({@see PayloadParticleReader}) throws on.
 *
 * List queries and single-record projection are inherently LOCAL operations, so they delegate straight
 * to the local arm (a foreign source's list results arrive through the RetrievalChannel, not this
 * hydrator — the particle-hydration path is the detail read, ADR-0161 §Retrieval).
 *
 * Port-composition only: the concrete arms are injected (the host binds its query-composing local
 * hydrator and its dereferencer-backed federated hydrator), so this router carries no host dependency and
 * no circular self-reference — it is constructed with explicit arms, never by resolving the port it binds.
 */
class SourcedParticleHydrator implements ParticleHydrator
{
    public function __construct(
        private ParticleSourceResolver $resolver,
        private ParticleHydrator $local,
        private ParticleHydrator $federated,
    ) {}

    public function hydrate(Model|array|string $source, ReadContext $ctx): Data
    {
        // An already-loaded record or raw payload is local by definition — only a string ref carries a
        // (possibly foreign) source authority worth resolving.
        if (! is_string($source)) {
            return $this->local->hydrate($source, $ctx);
        }

        return $this->resolver->resolve($source)->isForeign()
            ? $this->federated->hydrate($source, $ctx)
            : $this->local->hydrate($source, $ctx);
    }

    public function query(string $recordType, ReadContext $ctx): object
    {
        // A list read composes a local query; foreign list results arrive via the RetrievalChannel.
        return $this->local->query($recordType, $ctx);
    }

    public function project(Model $record, ReadContext $ctx): Data
    {
        // A loaded record is local — projection is the local arm's row-mapper.
        return $this->local->project($record, $ctx);
    }
}
