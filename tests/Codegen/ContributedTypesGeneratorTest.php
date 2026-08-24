<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Codegen\AmbientTypeIndex;
use Splicewire\Beam\Codegen\ContributedTypesGenerator;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Tests\TestCase;

/**
 * The CODEGEN half of the contribution seam (particle-contribution-seam ticket 22): the owner's
 * generated TypeScript type intersected with every registered slice.
 *
 * The runtime-reflection half — frame's `ContextManifest::forResource()`, which drives which columns
 * render — is a DIFFERENT mechanism, resolved by ticket 17 and executed by ticket 19. The two were
 * conflated on the map for three tickets before ticket 15 §A5 split them; they stay split here.
 */
class ContributedTypesGeneratorTest extends TestCase
{
    private function resources(): ParticleResourceRegistry
    {
        return $this->app->make(ParticleResourceRegistry::class);
    }

    private function contributions(): ResourceContributionRegistry
    {
        return $this->app->make(ResourceContributionRegistry::class);
    }

    private function generator(): ContributedTypesGenerator
    {
        return $this->app->make(ContributedTypesGenerator::class);
    }

    private function declareOwner(string $key, string $data): void
    {
        $this->resources()->register(new ParticleResource(
            key: $key,
            backing: TypeGenCrate::class,
            data: $data,
            filterable: false,
        ));
    }

    public function test_it_derives_an_intersection_per_contributed_to_resource(): void
    {
        $this->declareOwner('typegen-crates', TypeGenCrateData::class);

        $this->contributions()->register(new ResourceContribution(
            key: 'typegen-crates',
            as: 'weights',
            data: TypeGenWeightsData::class,
            value: fn (Model $record, ReadContext $ctx, array $filters): ?TypeGenWeightsData => null,
        ));

        $ts = $this->generator()->render($this->generator()->derive());

        $this->assertStringContainsString('declare namespace Splicewire.Beam.Particle.Read {', $ts);
        $this->assertStringContainsString(
            'export type TypegenCrates = Splicewire.Beam.Tests.Codegen.TypeGenCrateData & {',
            $ts
        );
        $this->assertStringContainsString(
            'weights: Splicewire.Beam.Tests.Codegen.TypeGenWeightsData | null,',
            $ts
        );
    }

    /**
     * ⚠️ The correction ticket 22's brief needed: a blanket `| null` would be wrong for two of the
     * estate's three live contributions.
     *
     * The null rule (ticket 04 §A7) says a key present-and-null means "the contributor ran and had
     * nothing for this record" — but whether a contribution CAN return null is a property of its value
     * arm, and `me.commerce`/`me.embed` both declare a non-nullable return with a docblock arguing the
     * point ("a slice rather than null: commerce IS installed"). Emitting `| null` for them would force
     * every consumer through a check the contract rules out.
     */
    public function test_nullability_is_read_off_the_value_arms_declared_return_type(): void
    {
        $this->declareOwner('typegen-crates', TypeGenCrateData::class);

        $this->contributions()->register(new ResourceContribution(
            key: 'typegen-crates',
            as: 'always',
            data: TypeGenWeightsData::class,
            value: fn (Model $record, ReadContext $ctx, array $filters): TypeGenWeightsData => new TypeGenWeightsData(1),
        ));

        $this->contributions()->register(new ResourceContribution(
            key: 'typegen-crates',
            as: 'sometimes',
            data: TypeGenWeightsData::class,
            value: fn (Model $record, ReadContext $ctx, array $filters): ?TypeGenWeightsData => null,
        ));

        // No declared return type proves nothing, so it widens rather than narrows.
        $this->contributions()->register(new ResourceContribution(
            key: 'typegen-crates',
            as: 'unknown',
            data: TypeGenWeightsData::class,
            value: fn (Model $record, ReadContext $ctx, array $filters) => null,
        ));

        $derived = $this->generator()->derive();

        $this->assertFalse($derived['typegen-crates']['slices']['always']['nullable']);
        $this->assertTrue($derived['typegen-crates']['slices']['sometimes']['nullable']);
        $this->assertTrue($derived['typegen-crates']['slices']['unknown']['nullable']);

        $ts = $this->generator()->render($derived);

        $this->assertStringContainsString('always: Splicewire.Beam.Tests.Codegen.TypeGenWeightsData,', $ts);
        $this->assertStringContainsString('sometimes: Splicewire.Beam.Tests.Codegen.TypeGenWeightsData | null,', $ts);
        $this->assertStringContainsString('unknown: Splicewire.Beam.Tests.Codegen.TypeGenWeightsData | null,', $ts);
    }

    /**
     * The absent half of the null rule, which is the whole reason the derivation is per-host: a key is
     * missing from the TYPE exactly when its package is missing from the deployment, so a stale read of
     * one is a compile error rather than a runtime `undefined`.
     */
    public function test_an_uncontributed_host_derives_no_key_at_all(): void
    {
        $this->declareOwner('typegen-crates', TypeGenCrateData::class);

        $this->assertSame([], $this->generator()->derive());
        $this->assertStringNotContainsString('TypegenCrates', $this->generator()->render([]));
    }

    /** An includes-only contribution adds no key to the ROW, so it must add none to the type either. */
    public function test_an_includes_only_contribution_derives_nothing(): void
    {
        $this->declareOwner('typegen-crates', TypeGenCrateData::class);

        $this->contributions()->register(new ResourceContribution(
            key: 'typegen-crates',
            as: 'weights',
            data: TypeGenWeightsData::class,
            includes: ['weights'],
        ));

        $this->assertSame([], $this->generator()->derive());
    }

    /**
     * A contribution whose owner nobody declares is an ordinary deployment fact — the contributor boots
     * first and stores a KEY, so beam-commerce installed without beam-tenancy contributes to nothing at
     * runtime. It must derive nothing rather than throw.
     */
    public function test_a_contribution_to_an_undeclared_resource_derives_nothing(): void
    {
        $this->contributions()->register(new ResourceContribution(
            key: 'never-declared',
            as: 'weights',
            data: TypeGenWeightsData::class,
            value: fn (Model $record, ReadContext $ctx, array $filters): ?TypeGenWeightsData => null,
        ));

        $this->assertSame([], $this->generator()->derive());
    }

    public function test_two_keys_deriving_one_type_name_throw(): void
    {
        $this->declareOwner('typegen-crates', TypeGenCrateData::class);
        $this->declareOwner('typegen_crates', TypeGenCrateData::class);

        foreach (['typegen-crates', 'typegen_crates'] as $key) {
            $this->contributions()->register(new ResourceContribution(
                key: $key,
                as: 'weights',
                data: TypeGenWeightsData::class,
                value: fn (Model $record, ReadContext $ctx, array $filters): ?TypeGenWeightsData => null,
            ));
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('both derive the TypeScript name [TypegenCrates]');

        $this->generator()->render($this->generator()->derive());
    }

    /**
     * The guard the map's three host-rooted-discovery failures earn: every slice lives in a PACKAGE, and
     * a host whose `#[TypeScript]` scan only reaches `app_path()` emits no type for it. The index is what
     * lets the command fail naming the class rather than writing a dangling reference.
     */
    public function test_the_ambient_index_reads_the_writers_nesting(): void
    {
        $index = AmbientTypeIndex::fromSource(<<<'TS'
        declare namespace Splicewire {
        namespace Beam {
        namespace Tenancy {
        namespace Data {
        export type TenantData = {
        id: string,
        llmConfig: {
        provider?: string,
        } | null,
        };
        }
        }
        namespace Commerce {
        namespace Data {
        export type TenantCommerceData = {
        plan: string,
        };
        }
        }
        }
        }
        TS);

        $this->assertTrue($index->has('Splicewire.Beam.Tenancy.Data.TenantData'));
        $this->assertTrue($index->has('Splicewire.Beam.Commerce.Data.TenantCommerceData'));
        $this->assertFalse($index->has('Splicewire.Beam.Embed.Data.AuthUserEmbedData'));

        $this->assertSame(
            ['Splicewire.Beam.Embed.Data.AuthUserEmbedData'],
            $index->missing([
                'Splicewire.Beam.Tenancy.Data.TenantData',
                'Splicewire.Beam.Embed.Data.AuthUserEmbedData',
            ])
        );
    }

    public function test_the_dotted_namespace_form_is_indexed_too(): void
    {
        $index = AmbientTypeIndex::fromSource(<<<'TS'
        declare namespace Splicewire.Beam.Particle.Read {
            export type Tenants = Splicewire.Beam.Tenancy.Data.TenantData & {
                commerce: Splicewire.Beam.Commerce.Data.TenantCommerceData | null,
            };
        }
        TS);

        $this->assertTrue($index->has('Splicewire.Beam.Particle.Read.Tenants'));
    }
}

class TypeGenCrate extends Model
{
    protected $table = 'typegen_crates';

    protected $guarded = [];
}

class TypeGenCrateData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

class TypeGenWeightsData extends Data
{
    public function __construct(
        public int $total,
    ) {}
}
