<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Codegen\AmbientTypeIndex;
use Splicewire\Beam\Codegen\ContributedTypesGenerator;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The enumeration half of the whole-surface emit guarantee: what the estate DECLARES, versus what a
 * host's `#[TypeScript]` scan actually wrote.
 *
 * Follows {@see ContributedTypesGeneratorTest}'s posture exactly — hand-registered fixtures, assert the
 * derivation, no emitted tree and no filesystem for the pure part.
 */
class DeclaredParticleTypesTest extends TestCase
{
    private function resources(): ParticleResourceRegistry
    {
        return $this->app->make(ParticleResourceRegistry::class);
    }

    private function operations(): ParticleOperationRegistry
    {
        return $this->app->make(ParticleOperationRegistry::class);
    }

    private function enumerator(): DeclaredParticleTypes
    {
        return $this->app->make(DeclaredParticleTypes::class);
    }

    /**
     * Beam declares particles of its OWN (`hooks`, `beam-schemas`, `git-repos` — the roots
     * `discoverParticleAttributes()` always scans), so the registries are never empty in a booted test.
     * Scoping to this file's fixtures is what keeps these assertions about the derivation rather than
     * about beam's current declaration count.
     *
     * @param  array<string, mixed>  $declared
     * @return array<string, mixed>
     */
    private function fixtures(array $declared): array
    {
        return array_filter(
            $declared,
            fn (string $class): bool => str_starts_with($class, __NAMESPACE__.'\\Declared'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function test_it_enumerates_every_dto_slot_a_resource_declares(): void
    {
        $this->resources()->register(new ParticleResource(
            key: 'declared-crates',
            backing: DeclaredCrate::class,
            data: DeclaredCrateData::class,
            input: DeclaredCrateInputData::class,
            editData: DeclaredCrateEditData::class,
            filterable: false,
        ));

        $declared = $this->enumerator()->declared();

        $this->assertSame(
            [DeclaredCrateData::class, DeclaredCrateInputData::class, DeclaredCrateEditData::class],
            array_keys($this->fixtures($declared))
        );

        $this->assertSame(['resource [declared-crates] data:'], $declared[DeclaredCrateData::class]);
        $this->assertSame(['resource [declared-crates] editData:'], $declared[DeclaredCrateEditData::class]);
    }

    /**
     * `input` is three-state and `data`/`editData` are nullable. `false` is a declared REFUSAL and `null`
     * is the undeclared residue — neither names a type, so neither can be missing from the emitted tree
     * and neither may be enumerated as if it could.
     */
    public function test_refused_and_undeclared_slots_name_no_type(): void
    {
        $this->resources()->register(new ParticleResource(
            key: 'refusing-crates',
            backing: DeclaredCrate::class,
            data: null,
            input: false,
            filterable: false,
        ));

        $this->assertSame([], $this->fixtures($this->enumerator()->declared()));
    }

    public function test_it_enumerates_an_operations_input_and_single_class_output(): void
    {
        $this->operations()->register(new ParticleOperation(
            resource: 'declared-crates',
            name: 'reweigh',
            kind: OperationKind::Write,
            handle: fn () => null,
            input: DeclaredCrateInputData::class,
            output: DeclaredCrateData::class,
        ));

        $declared = $this->enumerator()->declared();

        $this->assertSame(
            ['operation [declared-crates.reweigh] input:'],
            $declared[DeclaredCrateInputData::class]
        );
        $this->assertSame(
            ['operation [declared-crates.reweigh] output:'],
            $declared[DeclaredCrateData::class]
        );
    }

    /**
     * A Stream's `output` is an event-name map whose value is a LIST — one event name may cover several
     * payload variants discriminated by a DTO field. Reading only the first would drop the rest silently,
     * which is the exact failure this whole mechanism exists to remove.
     */
    public function test_a_streams_event_map_yields_every_payload_variant(): void
    {
        $this->operations()->register(new ParticleOperation(
            resource: 'declared-crates',
            name: 'watch',
            kind: OperationKind::Stream,
            handle: fn () => null,
            output: [
                'progress' => [DeclaredCrateData::class, DeclaredCrateEditData::class],
                'done' => [DeclaredCrateInputData::class],
            ],
        ));

        $declared = $this->enumerator()->declared();

        $this->assertSame(
            [DeclaredCrateData::class, DeclaredCrateEditData::class, DeclaredCrateInputData::class],
            array_keys($this->fixtures($declared))
        );

        $this->assertSame(
            ['operation [declared-crates.watch] output: [progress]'],
            $declared[DeclaredCrateEditData::class]
        );
    }

    /** One Data class commonly serves several declarations — it is one type, and every slot is named. */
    public function test_a_class_declared_twice_is_one_entry_carrying_both_slots(): void
    {
        $this->resources()->register(new ParticleResource(
            key: 'declared-crates',
            backing: DeclaredCrate::class,
            data: DeclaredCrateData::class,
            filterable: false,
        ));

        $this->operations()->register(new ParticleOperation(
            resource: 'declared-crates',
            name: 'reweigh',
            kind: OperationKind::Write,
            handle: fn () => null,
            output: DeclaredCrateData::class,
        ));

        $declared = $this->enumerator()->declared();

        $this->assertSame([DeclaredCrateData::class], array_keys($this->fixtures($declared)));
        $this->assertSame([
            'resource [declared-crates] data:',
            'operation [declared-crates.reweigh] output:',
        ], $declared[DeclaredCrateData::class]);
    }

    /**
     * The opt-out, and the reason no new mechanism was invented for it: `#[TypeScript]` is the existing
     * opt-IN, so its absence is the only opt-out the estate needs. A class that asked for nothing is
     * COUNTED, never failed — "could not look" and "nothing missing" are different facts.
     */
    public function test_the_partition_separates_asked_to_export_from_asked_for_nothing(): void
    {
        $partition = $this->enumerator()->partition([
            DeclaredCrateData::class,
            DeclaredCrateInputData::class,
            'Splicewire\\Beam\\Tests\\Codegen\\NoSuchDeclaredData',
        ]);

        $this->assertSame(
            [DeclaredCrateData::class => 'Splicewire.Beam.Tests.Codegen.DeclaredCrateData'],
            $partition['exported']
        );
        $this->assertSame([DeclaredCrateInputData::class], $partition['unexported']);
        $this->assertSame(['Splicewire\\Beam\\Tests\\Codegen\\NoSuchDeclaredData'], $partition['absent']);
    }

    /**
     * The name is spatie's, not ours: `GlobalNamespaceWriter::resolveReference()` joins LOCATION to NAME,
     * so `#[TypeScript(name:)]` replaces the last segment. With neither override the result is the dotted
     * FQCN {@see ContributedTypesGenerator} already emits.
     */
    public function test_a_renamed_export_maps_to_the_name_the_writer_will_use(): void
    {
        $partition = $this->enumerator()->partition([DeclaredCrateRenamedData::class]);

        $this->assertSame(
            [DeclaredCrateRenamedData::class => 'Splicewire.Beam.Tests.Codegen.Crate'],
            $partition['exported']
        );
    }

    /** The join the command makes: declared-and-asked-to-export, minus what the tree actually declares. */
    public function test_a_declared_export_absent_from_the_tree_is_named(): void
    {
        $this->resources()->register(new ParticleResource(
            key: 'declared-crates',
            backing: DeclaredCrate::class,
            data: DeclaredCrateData::class,
            filterable: false,
        ));

        $partition = $this->enumerator()->partition(
            array_keys($this->fixtures($this->enumerator()->declared()))
        );

        $index = AmbientTypeIndex::fromSource("declare namespace Splicewire.Beam.Tests.Codegen {\n}\n");

        $this->assertSame(
            ['Splicewire.Beam.Tests.Codegen.DeclaredCrateData'],
            $index->missing(array_values($partition['exported']))
        );
    }
}

class DeclaredCrate extends Model
{
    protected $table = 'declared_crates';

    protected $guarded = [];
}

#[TypeScript]
class DeclaredCrateData extends Data
{
    public function __construct(public int $id) {}
}

/** Deliberately un-exported: it asked for nothing, so nothing may be concluded about it. */
class DeclaredCrateInputData extends Data
{
    public function __construct(public string $name) {}
}

#[TypeScript]
class DeclaredCrateEditData extends Data
{
    public function __construct(public string $name) {}
}

#[TypeScript('Crate')]
class DeclaredCrateRenamedData extends Data
{
    public function __construct(public int $id) {}
}
