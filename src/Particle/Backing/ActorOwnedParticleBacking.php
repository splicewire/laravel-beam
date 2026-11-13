<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Collections\CollectionValidateStage;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleRequestError;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaBindingIndex;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Source\SourceWriteTier;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\Stages\AuthorizeStage;
use Splicewire\Beam\Write\Stages\EmitStage;
use Splicewire\Beam\Write\Stages\PersistStage;
use Splicewire\Beam\Write\Stages\ValidateStage;

/** Data-declared, actor-owned rows over the existing shared particle store and write engine. */
class ActorOwnedParticleBacking extends EloquentBacking implements AcceptsPayloadWrites, BindsResource, ProjectsPayload, SuppliesParticleWriter, UsesActorOwnership
{
    public const MANAGED_FIELDS = [
        'id', 'schema_ref', 'schemaRef', 'schema_id', 'schemaId', 'meta', 'owner', 'source_tier', 'external_ref',
        'source_revision', 'projector_version', 'fetched_at', 'head_version', 'migration_status',
        'created_at', 'createdAt', 'updated_at', 'updatedAt',
    ];

    protected ParticleResource $resource;

    private Request $request;

    public function __construct()
    {
        parent::__construct(BeamParticle::class);
    }

    public function forResource(ParticleResource $resource, Request $request): ResourceBacking
    {
        $bound = clone $this;
        $bound->resource = $resource;
        $bound->request = $request;
        if (! is_string($resource->data) || ! is_string($resource->input)
            || app(SchemaBindingIndex::class)->declaredDataClassFor($bound->binding()) !== $resource->data) {
            throw new LogicException('An owned particle requires declared read/input Data and an unambiguous schema binding.');
        }

        if ($resource->publicRead !== null) {
            throw new LogicException('Actor-owned storage does not offer Public Read. Select an explicit model backing instead.');
        }
        $input = app(Generator::class)->forResponse()->generate(new ReflectionClass($resource->input));
        $collisions = array_intersect(array_keys($input['properties'] ?? []), self::MANAGED_FIELDS);
        if ($collisions !== []) {
            throw new LogicException('Stored input Data ['.$resource->input.'] declares managed envelope fields: '.implode(', ', $collisions));
        }

        return $bound;
    }

    public function binding(): string
    {
        if (! isset($this->resource)) {
            throw new LogicException('The owned backing requires a resource and trusted request.');
        }

        return SchemaId::from($this->resource->schemaRef ?? $this->resource->data)->recordType();
    }

    /** @param array<string, mixed> $filters
     * @return Builder<BeamParticle>
     */
    public function query(array $filters): Builder
    {
        $owner = $this->request->user() === null ? null : $this->ownerFor($this->request->user());
        $query = $this->particle()->newQuery()->where('source_tier', SourceWriteTier::Local->value)->where('schema_ref', $this->binding());

        return $owner === null ? $query->whereRaw('1 = 0') : $query
            ->where('meta->owner->type', $owner['type'])->where('meta->owner->id', $owner['id']);
    }

    public function resolveForWrite(string $id, array $filters): ?WritableRecord
    {
        $model = $this->query($filters)->find($id);

        return $model === null ? null : new WritableRecord($model);
    }

    public function newRecord(): WritableRecord
    {
        return new WritableRecord($this->particle()->fill(['schema_ref' => $this->binding()]));
    }

    protected function particle(): BeamParticle
    {
        return new BeamParticle;
    }

    /** Trusted envelope identity; shared backings override this, never the write pipeline. */
    public function ownerFor(Authenticatable $actor): array
    {
        return ActorOwnedParticleAccess::owner($actor);
    }

    public function owns(Authenticatable $actor, BeamParticle $record): bool
    {
        $owner = $this->ownerFor($actor);

        return ($record->meta['owner']['type'] ?? null) === $owner['type']
            && ($record->meta['owner']['id'] ?? null) === $owner['id'];
    }

    /** Merge only projection inputs; schema, owner and provenance stay in the server envelope. */
    public static function project(ParticleResource $resource, Model $record, ?string $data = null): Data
    {
        if (! $record instanceof BeamParticle || SchemaId::from($record->getAttribute('schema_ref'))->recordType()
            !== SchemaId::from($resource->schemaRef ?? $resource->data)->recordType()) {
            throw new LogicException('The stored projection does not match its declared schema.');
        }
        $data ??= app(SchemaBindingIndex::class)->declaredDataClassFor($record->getAttribute('schema_ref'));
        if ($data === null || ! is_a($data, Data::class, true)) {
            throw new LogicException('No declared Data projection for this stored schema.');
        }

        return $data::from(array_merge($record->getAttribute('payload') ?? [], [
            'id' => $record->getKey(), 'created_at' => $record->getAttribute('created_at'), 'updated_at' => $record->getAttribute('updated_at'),
        ]));
    }

    /** @param array<string, mixed> $input */
    public static function assertInput(array $input): void
    {
        $forbidden = array_intersect(array_keys($input), self::MANAGED_FIELDS);
        if ($forbidden !== []) {
            throw CodedValidationException::many(array_fill_keys($forbidden, [ParticleRequestError::ServerManagedField, []]));
        }
    }

    /** @return array<string, mixed> */
    public static function payload(mixed $input): array
    {
        if (! $input instanceof Data) {
            throw new LogicException('Stored particle writes require their declared input Data.');
        }

        return $input->toArray();
    }

    /** @return array<string, mixed> */
    public function registerSchema(): array
    {
        $input = $this->resource->input;
        if (! is_string($input) || ! is_a($input, Data::class, true)) {
            throw new LogicException('Stored particle input must be a Data class.');
        }
        $schema = app(Generator::class)->forResponse()->generate(new ReflectionClass($input));
        $schema['$id'] = $this->binding().'/'.(SchemaId::from($this->resource->schemaRef ?? $this->resource->data)->version() ?? 1);
        self::assertInput($schema['properties'] ?? []);
        $schema['additionalProperties'] = false;
        $registry = app(SchemaRegistry::class);
        $existing = $registry->get($schema['$id']);
        $fingerprint = SchemaFingerprint::of($schema);
        if ($existing !== null && SchemaFingerprint::of($existing) !== $fingerprint) {
            throw new LogicException('Input Data ['.$input.'] changed its frozen schema ['.$schema['$id'].']; declare a new schemaRef version and its migration.');
        }
        if ($existing === null) {
            try {
                $registry->register($schema);
            } catch (SchemaRegistryConflict $error) {
                throw new LogicException('Input Data ['.$input.'] conflicts with schema ['.$schema['$id'].']; declare a new schemaRef version.', previous: $error);
            }
        }
        $target = app(SchemaTargetResolver::class)->targetFor($this->binding());
        if ($target === []) {
            throw CodedValidationException::for('schema', ParticleRequestError::SchemaTargetUnavailable);
        }

        return $target;
    }

    public function writer(WriteGate $gate): ParticleWriter
    {
        $targets = app(SchemaTargetResolver::class);
        $acceptance = app(AcceptanceGate::class);
        $events = app(Dispatcher::class);

        return new ParticleWriter($gate, $targets, $acceptance, $events, [
            new AuthorizeStage($gate), new ActorOwnedParticleWriteStage($this),
            new CollectionValidateStage(new ValidateStage($targets, $acceptance)),
            new PersistStage, new EmitStage($events),
        ]);
    }

    public function hasWritePolicy(): bool
    {
        return $this->resource->policy !== null && $this->resource->policy !== '';
    }

    public function permitsWrite(Model $model, Authenticatable $actor): bool
    {
        return $this->hasWritePolicy() && Gate::forUser($actor)->allows($this->resource->policy, $model);
    }

    public function request(): Request
    {
        return $this->request;
    }
}
