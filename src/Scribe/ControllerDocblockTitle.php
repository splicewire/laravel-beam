<?php

namespace Splicewire\Beam\Scribe;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Metadata\GetFromDocBlocks;
use Knuckles\Scribe\Extracting\Strategies\Metadata\GetFromMetadataAttributes;
use Knuckles\Scribe\Tools\DocumentationConfig;
use ReflectionMethod;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/** Distinguish generic dispatcher comments from deliberately authored endpoint metadata. */
final class ControllerDocblockTitle
{
    public static function isImplementationDetail(ExtractedEndpointData $endpoint, DocumentationConfig $config): bool
    {
        if (! $endpoint->method instanceof ReflectionMethod || ! in_array(
            $endpoint->method->getDeclaringClass()->getName(),
            [ParticleController::class, ParticleOperationController::class],
            true,
        )) {
            return false;
        }

        // Use Scribe's own attribute lookup, including inherited controller and FormRequest metadata.
        $attributes = (new GetFromMetadataAttributes($config))($endpoint);
        if (($attributes['title'] ?? '') !== '') {
            return false;
        }

        $docblock = (new GetFromDocBlocks($config))($endpoint);

        // A prior custom strategy may already have supplied a title; only replace this exact source.
        return ($docblock['title'] ?? '') !== ''
            && $endpoint->metadata->title === $docblock['title'];
    }
}
