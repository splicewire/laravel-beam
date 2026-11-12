<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleResource;

/** Explicit resource policy; never a global policy for the shared BeamParticle model. */
class ActorOwnedParticleAccess implements ResourceReadPolicy
{
    public const WRITE = 'beam.actor-owned-particle.write';

    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        return $actor === null ? Response::deny('An owned particle requires an authenticated actor.') : Response::allow();
    }

    public static function permits(?Authenticatable $actor, Model|string|null $subject = null): bool
    {
        return $actor !== null && ($subject === null || $subject === BeamParticle::class
            || ($subject instanceof BeamParticle && (! $subject->exists || self::owns($actor, $subject))));
    }

    public static function owns(Authenticatable $actor, BeamParticle $record): bool
    {
        $expected = self::owner($actor);

        return ($record->meta['owner']['type'] ?? null) === $expected['type']
            && ($record->meta['owner']['id'] ?? null) === $expected['id'];
    }

    /** @return array{type: string, id: string} */
    public static function owner(Authenticatable $actor): array
    {
        return ['type' => $actor instanceof Model ? $actor->getMorphClass() : $actor::class, 'id' => (string) $actor->getAuthIdentifier()];
    }
}
