<?php

namespace Splicewire\Beam\Tests\Source;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Source\Contracts\ForeignSourceProjector;
use Splicewire\Beam\Source\ParticleShadower;
use Splicewire\Beam\Source\SourceGrant;
use Splicewire\Beam\Source\SourceWriteTier;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The two-intensity shadow writer (ADR-0161 Position 1; sourced-particles ticket 06), proven
 * framework-free (beam's own testbench boot is mid-migration under a concurrent beam⇄frame refactor).
 * Hand-built fakes for the writer / target resolver / projector let us assert the ORCHESTRATION —
 * project → stamp → write, the light-vs-full intensity, the promote guard, and the tier rule — without
 * a container or a database. DB-backed column/cast behaviour is verified host-side (see report).
 */
class ParticleShadowerTest extends TestCase
{
    private function shadower(RecordingWriter $writer, ?ForeignSourceProjector $projector = null): ParticleShadower
    {
        return new ParticleShadower(
            $writer,
            new FixedTargetResolver(['$id' => 'content/article/1']),
            $projector ?? new FixedProjector(['title' => 'projected']),
        );
    }

    public function test_shadow_projects_then_writes_and_stamps_provenance_and_tier(): void
    {
        $writer = new RecordingWriter;
        $model = new FakeParticle('content/article');

        $result = $this->shadower($writer)->shadow(
            $model,
            ['raw' => 'foreign'],
            SourceGrant::for('federation:grant-42', ['content/article']),
            SourceWriteTier::ShadowPinned,
            'frag-9',
            sourceRevision: 'rev-3',
            projectorVersion: 'v2',
        );

        // Projected payload (not the raw foreign) reached the writer.
        $this->assertSame(['title' => 'projected'], $writer->payload);
        $this->assertSame($model, $result);

        // Provenance + tier stamped on the model before the write.
        $this->assertSame(SourceWriteTier::ShadowPinned, $model->getAttribute('source_tier'));
        $this->assertSame('frag-9', $model->getAttribute('external_ref'));
        $this->assertSame('rev-3', $model->getAttribute('source_revision'));
        $this->assertSame('v2', $model->getAttribute('projector_version'));
        $this->assertNotNull($model->getAttribute('fetched_at'));

        // Written under the SourceGrant actor (so SourceWriteGate authorizes it).
        $this->assertInstanceOf(SourceGrant::class, $writer->actor);
    }

    public function test_cached_shadow_write_suppresses_the_persisted_event(): void
    {
        $writer = new RecordingWriter;

        $this->shadower($writer)->shadow(
            new FakeParticle('content/article'),
            ['raw' => 'x'],
            SourceGrant::for('s', ['content/article']),
            SourceWriteTier::ShadowCached,
            'frag-1',
        );

        // LIGHT write: emit suppressed — no adoption side-effects (embedding jobs, indexing) fire.
        $this->assertFalse($writer->emit);
    }

    public function test_pinned_shadow_write_emits_the_persisted_event(): void
    {
        $writer = new RecordingWriter;

        $this->shadower($writer)->shadow(
            new FakeParticle('content/article'),
            ['raw' => 'x'],
            SourceGrant::for('s', ['content/article']),
            SourceWriteTier::ShadowPinned,
            'frag-1',
        );

        $this->assertTrue($writer->emit);
    }

    public function test_promote_re_stamps_pinned_and_runs_the_full_pipeline_once(): void
    {
        $writer = new RecordingWriter;
        $record = new FakeParticle('content/article');
        $record->setAttribute('source_tier', SourceWriteTier::ShadowCached);
        $record->setAttribute('external_ref', 'frag-9');
        $record->setAttribute('payload', ['title' => 'projected']);

        $this->shadower($writer)->promote($record);

        // Re-stamped to pinned, full pipeline (emit=true), the stored payload re-written once.
        $this->assertSame(SourceWriteTier::ShadowPinned, $record->getAttribute('source_tier'));
        $this->assertTrue($writer->emit);
        $this->assertSame(1, $writer->calls);
        $this->assertSame(['title' => 'projected'], $writer->payload);
    }

    public function test_promote_rejects_a_non_cached_record(): void
    {
        $writer = new RecordingWriter;

        $pinned = new FakeParticle('content/article');
        $pinned->setAttribute('source_tier', SourceWriteTier::ShadowPinned);

        $this->expectException(\InvalidArgumentException::class);
        $this->shadower($writer)->promote($pinned);
    }

    public function test_a_source_may_not_write_the_local_tier(): void
    {
        $writer = new RecordingWriter;

        $this->expectException(\InvalidArgumentException::class);
        $this->shadower($writer)->shadow(
            new FakeParticle('content/article'),
            ['raw' => 'x'],
            SourceGrant::for('s', ['content/article']),
            SourceWriteTier::Local,
            'frag-1',
        );

        $this->assertSame(0, $writer->calls);
    }

    public function test_an_unschematized_target_throws_before_any_write(): void
    {
        $writer = new RecordingWriter;

        // The real ladder-backed projector throws on an unschematized target; the FixedProjector mirrors
        // that contract so the ephemeral-only floor is asserted framework-free.
        $shadower = new ParticleShadower(
            $writer,
            new FixedTargetResolver([]), // no $id — unschematized
            new UnschematizedRejectingProjector,
        );

        $this->expectException(\InvalidArgumentException::class);
        $shadower->shadow(
            new FakeParticle('content/article'),
            ['raw' => 'x'],
            SourceGrant::for('s', ['content/article']),
            SourceWriteTier::ShadowCached,
            'frag-1',
        );

        $this->assertSame(0, $writer->calls);
    }
}

/** A ParticleWriter that records its call instead of touching a gate/db/dispatcher. */
class RecordingWriter extends ParticleWriter
{
    public int $calls = 0;

    public ?array $payload = null;

    public mixed $actor = null;

    public ?bool $emit = null;

    public function __construct()
    {
        // Deliberately bypass the parent constructor — this fake never uses the real deps.
    }

    public function write(Model|string $target, array $payload, mixed $actor = null, ?Closure $after = null, bool $emit = true): Model
    {
        $this->calls++;
        $this->payload = $payload;
        $this->actor = $actor;
        $this->emit = $emit;

        return $target instanceof Model ? $target : new $target;
    }
}

/** A SchemaTargetResolver returning a fixed target schema document. */
class FixedTargetResolver implements SchemaTargetResolver
{
    public function __construct(private array $schema) {}

    public function targetFor(string $recordType, ?int $version = null): array
    {
        return $this->schema;
    }
}

/** A projector returning a fixed projected payload. */
class FixedProjector implements ForeignSourceProjector
{
    public function __construct(private array $projected) {}

    public function project(array $foreignPayload, array $targetSchema): array
    {
        return $this->projected;
    }
}

/** A projector that mirrors the ladder's unschematized-target contract. */
class UnschematizedRejectingProjector implements ForeignSourceProjector
{
    public function project(array $foreignPayload, array $targetSchema): array
    {
        if (! isset($targetSchema['$id'])) {
            throw new \InvalidArgumentException('unschematized target — ephemeral-only');
        }

        return $foreignPayload;
    }
}

/**
 * A minimal particle stand-in: an attribute bag with the schema-binding seam the writer/shadower read,
 * WITHOUT booting Eloquent (no db). It mimics recordType() off schema_ref and sourceTier() off the tier
 * attribute, so the orchestration is exercised exactly as against a real trait-bearing model.
 */
class FakeParticle extends Model
{
    protected $guarded = [];

    public function __construct(?string $schemaRef = null)
    {
        parent::__construct();

        if ($schemaRef !== null) {
            $this->setAttribute('schema_ref', $schemaRef);
        }
    }

    public function recordType(): ?string
    {
        $ref = $this->getAttribute('schema_ref');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function sourceTier(): SourceWriteTier
    {
        $tier = $this->getAttribute('source_tier');

        if ($tier instanceof SourceWriteTier) {
            return $tier;
        }

        return is_string($tier) && $tier !== '' ? SourceWriteTier::from($tier) : SourceWriteTier::Local;
    }
}
