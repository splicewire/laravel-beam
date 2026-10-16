<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Validation\ValidationException;
use LogicException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Resolve on every request: a resource declaration must never cache tenant schema state. */
final class CollectionSchemaResolver
{
    public function __construct(private SchemaTargetResolver $targets, private SchemaRegistry $registry) {}

    public function resolve(string $stem): CollectionDefinition
    {
        if (! filter_var($stem, FILTER_VALIDATE_URL) || SchemaId::from($stem)->version() !== null) {
            throw ValidationException::withMessages(['collection' => 'Collection must be an absolute, unversioned schema stem.']);
        }

        $schema = $this->targets->targetFor($stem);
        if (! array_key_exists('x-beam-collection', $schema)) {
            throw new NotFoundHttpException('The current schema does not declare a Collection.');
        }

        return new CollectionDefinition($schema);
    }

    /** @return list<string> Exact identities, not a prefix that could include another collection. */
    public function recordRefs(string $stem): array
    {
        $this->resolve($stem);
        if (! $this->registry instanceof EnumeratesVersions) {
            throw new LogicException('Collection records require a schema registry that enumerates registered versions.');
        }
        $versions = $this->registry->versionsFor($stem);
        sort($versions, SORT_NUMERIC);

        return [$stem, ...array_map(fn (int $version): string => $stem.'/'.$version, $versions)];
    }
}
