<?php

namespace Splicewire\Beam\Read;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\ParticleResource;

final class ResolvedRecordPublicProjector
{
    /**
     * A guest (or a signed-in reader deliberately served through the public floor) gets the
     * resource's declared Public Read fields for a resolved record, while its schema reference
     * remains in the transport envelope.
     *
     * @return array<string, mixed>
     */
    public static function project(?ParticleResource $resource, ResolvedRecord $resolved, Request $request): array
    {
        $record = $resolved->record;

        if ($resource !== null
            && ReadContext::projectsPublic($request, $resource->key)
            && $resource->publicRead !== null
            && $resource->publicRead !== $resource->data) {
            $record = $resource->publicRead::from($record->toArray());
        }

        return array_merge($record->toArray(), ['schemaRef' => $resolved->schemaRef]);
    }
}
