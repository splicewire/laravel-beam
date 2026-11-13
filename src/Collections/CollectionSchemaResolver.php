<?php

namespace Splicewire\Beam\Collections;

use LogicException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Localization\CodedNotFoundHttpException;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaId;

/** Transient host dependencies with scope-local memoization by registry identity and revision. */
final class CollectionSchemaResolver
{
    private CollectionResolutionScope $scope;

    public function __construct(private SchemaTargetResolver $targets, private SchemaRegistry $registry, ?CollectionResolutionScope $scope = null)
    {
        $this->scope = $scope ?? new CollectionResolutionScope;
    }

    public function resolve(string $stem): CollectionDefinition
    {
        if (! filter_var($stem, FILTER_VALIDATE_URL) || SchemaId::from($stem)->version() !== null) {
            throw CodedValidationException::for('collection', CollectionError::StemInvalid);
        }

        // Host target policies may change independently of the registry, so only the native
        // registry-latest resolver participates. Unknown registry revision semantics stay fresh.
        $version = get_class($this->targets) === \Splicewire\Beam\Schema\RegistrySchemaTargetResolver::class
            && $this->registry instanceof \Splicewire\Beam\Schema\Contracts\SchemaRegistryCacheVersion
            ? $this->registry->cacheVersion() : null;
        $key = $version === null ? null : $stem.'|'.$version;
        if ($key !== null && ($cached = $this->scope->get($this->registry, $key)) !== null) {
            return $cached;
        }
        $schema = $this->targets->targetFor($stem);
        if ($schema === []) {
            throw new CodedNotFoundHttpException(CollectionError::SchemaTargetUnavailable);
        }
        if (! array_key_exists('x-beam-collection', $schema)) {
            throw new CodedNotFoundHttpException(CollectionError::SchemaHasNoCollection);
        }

        $definition = new CollectionDefinition($schema);
        if ($key !== null) {
            $this->scope->put($this->registry, $key, $definition);
        }

        return $definition;
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
