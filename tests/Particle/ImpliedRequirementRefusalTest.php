<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Agreements\NullRequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Codegen\BeamTypeScriptTransformerExtension;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\PackageTypeRoots;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * agreements 04: a host attaches a Requirement to an op it does not own, which declares no `errors:428`. The
 * {@see RequirementDeclarations} port implies the slot through {@see ParticleOperation::declaredErrors()}; without the
 * seam nothing changes.
 */
class ImpliedRequirementRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('implied_probe_rows', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function test_the_null_port_implies_nothing_and_the_guard_still_throws(): void
    {
        // agreements 05: beam's default is the slot port, which with no `requires:` slot declares exactly what Null did.
        $this->assertInstanceOf(\Splicewire\Beam\Agreements\SlotRequirementDeclarations::class, $this->app->make(RequirementDeclarations::class));
        $this->assertSame([], $this->app->make(RequirementDeclarations::class)->all());
        $this->assertFalse((new NullRequirementDeclarations)->declares('implied-probe-rows.act'));
        $this->app->instance(RequirementResolver::class, new ImpliedOutstandingResolver);
        $operation = $this->mount();
        ImpliedProbeRow::query()->create([]);
        $this->withoutExceptionHandling();

        $this->assertNull($operation->declaredErrors());

        try {
            $this->postJson('/implied-probe-rows/1/act', []);
            $this->fail('an outstanding requirement on an op neither declaring nor implied errors:428 did not fail loudly');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('does not declare', $exception->getMessage());
        }
    }

    public function test_a_port_declared_behavior_answers_428_on_an_op_whose_author_declared_none(): void
    {
        $this->app->instance(RequirementDeclarations::class, new FixedRequirementDeclarations(['implied-probe-rows.act']));
        $this->app->instance(RequirementResolver::class, new ImpliedOutstandingResolver);
        $operation = $this->mount();
        ImpliedProbeRow::query()->create([]);

        $this->assertNull($operation->errors);
        $this->assertSame([428 => RequirementRefusalData::class], $operation->declaredErrors());

        $this->postJson('/implied-probe-rows/1/act', [])
            ->assertStatus(428)
            ->assertJsonPath('requirements.0.key', 'voice-release')
            ->assertJsonPath('requirements.0.member', 'agreement_member');
    }

    public function test_the_implied_slot_keeps_the_authors_other_refusals_and_never_replaces_an_authored_428(): void
    {
        $this->app->instance(RequirementDeclarations::class, new FixedRequirementDeclarations(['probe.other', 'probe.own']));

        $other = new ParticleOperation(resource: 'probe', name: 'other', kind: OperationKind::Write, handle: fn () => null,
            errors: [422 => ImpliedProbeOutput::class]);
        $own = new ParticleOperation(resource: 'probe', name: 'own', kind: OperationKind::Write, handle: fn () => null,
            errors: [428 => ImpliedProbeOutput::class]);

        $this->assertSame([422 => ImpliedProbeOutput::class, 428 => RequirementRefusalData::class], $other->declaredErrors());
        $this->assertSame([422 => ImpliedProbeOutput::class], $other->errors);
        $this->assertSame([428 => ImpliedProbeOutput::class], $own->declaredErrors());
    }

    public function test_generated_types_include_the_428_body_only_when_the_port_declares_the_behavior(): void
    {
        $this->assertStringNotContainsString(class_basename(RequirementRefusalData::class), $this->generatedTypes());

        $this->app->instance(RequirementDeclarations::class, new FixedRequirementDeclarations(['requirement-fixtures.act']));

        $this->assertStringContainsString(class_basename(RequirementRefusalData::class), $this->generatedTypes());
    }

    private function generatedTypes(): string
    {
        $directory = sys_get_temp_dir().'/beam-implied-requirement-types-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($directory);

        try {
            $operations = new ParticleOperationRegistry;
            $operations->register(new ParticleOperation(
                resource: 'requirement-fixtures',
                name: 'act',
                kind: OperationKind::Write,
                handle: fn () => null,
                output: ImpliedProbeOutput::class,
            ));

            $config = TypeScriptTransformerConfigFactory::create()
                ->outputDirectory($directory)
                ->extension(new BeamTypeScriptTransformerExtension(
                    new DeclaredParticleTypes(new ParticleResourceRegistry, $operations, new ResourceContributionRegistry),
                    new PackageTypeRoots([]),
                ))
                ->writer(new GlobalNamespaceWriter('generated.d.ts'))
                ->get();

            TypeScriptTransformer::create($config)->execute();

            return (string) file_get_contents($directory.'/generated.d.ts');
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function mount(): ParticleOperation
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'implied-probe-rows',
            backing: ImpliedProbeRow::class,
        ));

        $operation = new ParticleOperation(
            resource: 'implied-probe-rows',
            name: 'act',
            kind: OperationKind::Write,
            model: ImpliedProbeRow::class,
            input: ImpliedProbeInput::class,
            output: ImpliedProbeOutput::class,
            handle: fn () => ['ok' => true],
        );
        $this->app->make(ParticleOperationRegistry::class)->register($operation);
        Particle::ops('implied-probe-rows', 'implied-probe-rows', 'act');

        return $operation;
    }
}

class FixedRequirementDeclarations implements RequirementDeclarations
{
    /** @param  list<string>  $behaviors */
    public function __construct(private array $behaviors) {}

    public function declares(string $behavior): bool
    {
        return in_array($behavior, $this->behaviors, true);
    }

    public function requirementsFor(string $behavior): array
    {
        return $this->declares($behavior) ? [new \Splicewire\Beam\Agreements\Requires('voice-release')] : [];
    }

    public function all(): array
    {
        return array_combine($this->behaviors, array_map($this->requirementsFor(...), $this->behaviors));
    }
}

class ImpliedOutstandingResolver implements RequirementResolver
{
    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        return [new OutstandingRequirementData(
            key: 'voice-release',
            version: 2,
            digest: 'sha256:abc',
            kind: 'voice',
            title: 'Voice release',
            scope: 'party',
            subject: null,
            readUrl: '/agreements/voice-release',
            acceptOperation: null,
            member: 'agreement_member',
        )];
    }
}

class ImpliedProbeRow extends Model
{
    protected $table = 'implied_probe_rows';

    protected $guarded = [];

    public $timestamps = false;
}

class ImpliedProbeInput extends Data
{
    public function __construct(#[MapInputName('agreement_member')] public AgreementMemberData $member, public string $value) {}

    public static function rules(): array
    {
        return ['value' => ['required', 'string']];
    }
}

class ImpliedProbeOutput extends Data
{
    public function __construct(public bool $ok) {}
}
