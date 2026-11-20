<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class RequirementAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('requirement_probe_rows', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function test_default_resolver_keeps_an_operation_unchanged(): void
    {
        $this->mount();

        $this->createProbeRow();

        $this->postJson('/requirement-probe-rows/1/act', ['agreement_member' => ['key' => 'voice-release', 'version' => 2, 'digest' => 'sha256:abc'], 'value' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_non_http_admission_returns_the_same_declared_list_as_the_http_428(): void
    {
        $resolver = $this->bindOutstandingResolver();
        $this->mount();
        $this->createProbeRow();

        $http = $this->postJson('/requirement-probe-rows/1/act', ['agreement_member' => ['key' => 'voice-release', 'version' => 2, 'digest' => 'sha256:abc']]);
        $http->assertStatus(428)->assertExactJson([
            'requirements' => [[
                'key' => 'voice-release',
                'version' => 2,
                'digest' => 'sha256:abc',
                'kind' => 'voice',
                'title' => 'Voice release',
                'scope' => 'party',
                'subject' => null,
                'readUrl' => '/agreements/voice-release',
                'acceptOperation' => null,
                'member' => 'agreement_member',
            ]],
        ]);

        $admission = $this->app->make(RequirementAdmission::class);
        $direct = $admission->inspect(
            party: null,
            behavior: 'requirement-probe-rows.act',
            subject: RequirementProbeRow::query()->findOrFail(1),
            submittedMembers: $admission->submittedMembers(RequirementProbeInput::class, [
                'agreement_member' => ['key' => 'voice-release', 'version' => 2, 'digest' => 'sha256:abc'],
            ]),
        );

        $this->assertNotNull($direct);
        $this->assertSame($http->json('requirements'), $direct->toArray()['requirements']);
        $this->assertEquals(new AgreementMemberData('voice-release', 2, 'sha256:abc'), $resolver->submittedMembers['agreement_member']);
        $this->assertSame('requirement-probe-rows.act', $resolver->behavior);
    }

    public function test_ability_refusal_precedes_admission_and_does_not_disclose_requirements(): void
    {
        $resolver = $this->bindOutstandingResolver();
        Gate::define('requirement-probe-rows.act', fn () => false);
        $this->mount(ability: 'requirement-probe-rows.act');
        $this->createProbeRow();

        $this->postJson('/requirement-probe-rows/1/act', [])->assertForbidden();

        $this->assertSame(0, $resolver->calls);
    }

    public function test_outstanding_requirement_refuses_before_input_validation(): void
    {
        $this->bindOutstandingResolver();
        $this->mount();
        $this->createProbeRow();

        $this->postJson('/requirement-probe-rows/1/act', [])->assertStatus(428);
    }

    public function test_an_outstanding_requirement_declares_exactly_one_remedy(): void
    {
        foreach ([[null, null], ['agreements.accept', 'member']] as [$acceptOperation, $member]) {
            try {
                new OutstandingRequirementData(
                    key: 'voice-release',
                    version: 2,
                    digest: 'sha256:abc',
                    kind: 'voice',
                    title: 'Voice release',
                    scope: 'party',
                    subject: null,
                    readUrl: '/agreements/voice-release',
                    acceptOperation: $acceptOperation,
                    member: $member,
                );
                $this->fail('a requirement without exactly one remedy was accepted');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('exactly one remedy', $exception->getMessage());
            }
        }
    }

    private function createProbeRow(): RequirementProbeRow
    {
        return RequirementProbeRow::query()->create([]);
    }

    private function bindOutstandingResolver(): RequirementAdmissionSpyResolver
    {
        $resolver = new RequirementAdmissionSpyResolver;
        $this->app->instance(RequirementResolver::class, $resolver);

        return $resolver;
    }

    private function mount(?string $ability = null): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'requirement-probe-rows',
            backing: RequirementProbeRow::class,
        ));

        $operation = new ParticleOperation(
            resource: 'requirement-probe-rows',
            name: 'act',
            kind: OperationKind::Write,
            model: RequirementProbeRow::class,
            ability: $ability,
            input: RequirementProbeInput::class,
            output: RequirementProbeOutput::class,
            errors: [428 => RequirementRefusalData::class],
            handle: fn () => ['ok' => true],
        );
        $this->app->make(ParticleOperationRegistry::class)->register($operation);
        Particle::ops('requirement-probe-rows', 'requirement-probe-rows', 'act');
    }
}

class RequirementAdmissionSpyResolver implements RequirementResolver
{
    public int $calls = 0;

    public string $behavior = '';

    public array $submittedMembers = [];

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        $this->calls++;
        $this->behavior = $behavior;
        $this->submittedMembers = $submittedMembers;

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

class RequirementProbeRow extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'requirement_probe_rows';

    protected $guarded = [];

    public $timestamps = false;
}

class RequirementProbeInput extends Data
{
    public function __construct(#[MapInputName('agreement_member')] public AgreementMemberData $member, public string $value) {}

    public static function rules(): array
    {
        return ['value' => ['required', 'string']];
    }
}

class RequirementProbeOutput extends Data
{
    public function __construct(public bool $ok) {}
}
