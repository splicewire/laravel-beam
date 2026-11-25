<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementRefused;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Stages\RequirementAdmissionStage;
use Splicewire\Beam\Write\WriteContext;
use Splicewire\Beam\Write\WriteRequirement;

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
            ->assertExactJson(['ok' => true]);
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
        $this->assertEquals(new AgreementMemberData('voice-release', 2, 'sha256:abc'), $resolver->submittedMembers['voice-release']);
        $this->assertSame('requirement-probe-rows.act', $resolver->behavior);
    }

    public function test_occurrence_and_subject_create_refusals_use_the_declared_member(): void
    {
        $resolver = new ScopedRequirementAdmissionResolver;
        $this->app->instance(RequirementResolver::class, $resolver);
        $admission = $this->app->make(RequirementAdmission::class);

        $ask = function (array $payload, ?Model $subject = null, string $input = RequirementProbeInput::class) use ($admission): WriteContext {
            $requirement = new WriteRequirement(
                behavior: 'requirement-probe-rows.act',
                subject: $subject,
                submittedMembers: $admission->submittedMembers($input, $payload),
                input: $input,
            );

            return (new RequirementAdmissionStage)->handle(
                new WriteContext(new RequirementProbeRow, [], requirement: $requirement),
                function (WriteContext $context): WriteContext {
                    $context->payload['result'] = 'passed';

                    return $context;
                },
            );
        };

        try {
            $ask([]);
            $this->fail('outstanding requirements did not refuse the write');
        } catch (RequirementRefused $refused) {
            $items = $refused->refusal->requirements;
            $this->assertSame(['occurrence', 'subject', 'party', 'organisation'], array_map(fn ($item) => $item->scope, $items));
            $this->assertNull($items[0]->acceptOperation);
            $this->assertSame('agreement_member', $items[0]->member);
            $this->assertNull($items[1]->acceptOperation);
            $this->assertSame('agreement_member', $items[1]->member);
            foreach (array_slice($items, 2) as $item) {
                $this->assertSame('agreements.accept', $item->acceptOperation);
                $this->assertNull($item->member);
            }
        }

        try {
            $ask([], new RequirementProbeRow);
            $this->fail('existing-subject requirements did not refuse the write');
        } catch (RequirementRefused $refused) {
            $items = $refused->refusal->requirements;
            $this->assertSame('agreement_member', $items[0]->member);
            $this->assertSame('agreements.accept', $items[1]->acceptOperation);
            $this->assertNull($items[1]->member);
            $this->assertSame('agreements.accept', $items[2]->acceptOperation);
            $this->assertSame('agreements.accept', $items[3]->acceptOperation);
        }

        $this->assertSame('passed', $ask([
            'agreement_member' => ['key' => 'occurrence', 'version' => 1, 'digest' => 'occurrence-digest'],
        ])->payload['result']);

        try {
            $ask([], null, CollectionAndSingleRequirementProbeInput::class);
            $this->fail('the DataCollectionOf member input was not selected');
        } catch (RequirementRefused $refused) {
            $this->assertSame('agreement_members', $refused->refusal->requirements[0]->member);
        }
    }

    public function test_ambiguous_single_member_inputs_fail_loudly(): void
    {
        $this->app->instance(RequirementResolver::class, new ScopedRequirementAdmissionResolver);

        try {
            (new RequirementAdmissionStage)->handle(
                new WriteContext(new RequirementProbeRow, [], requirement: new WriteRequirement(
                    behavior: 'requirement-probe-rows.act',
                    input: AmbiguousRequirementProbeInput::class,
                )),
                fn (WriteContext $context): WriteContext => $context,
            );
            $this->fail('ambiguous AgreementMemberData input properties were silently resolved');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Input [Splicewire\Beam\Tests\Particle\AmbiguousRequirementProbeInput] declares multiple AgreementMemberData properties [first, second].',
                $exception->getMessage(),
            );
        }
    }

    public function test_occurrence_refusal_without_a_member_input_fails_with_a_pinned_message(): void
    {
        $this->app->instance(RequirementResolver::class, new ScopedRequirementAdmissionResolver);

        try {
            (new RequirementAdmissionStage)->handle(
                new WriteContext(new RequirementProbeRow, [], requirement: new WriteRequirement(
                    behavior: 'requirement-probe-rows.act',
                    input: RequirementProbeOutput::class,
                )),
                fn (WriteContext $context): WriteContext => $context,
            );
            $this->fail('an occurrence requirement without an input member did not fail loudly');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Occurrence requirement [occurrence] for [requirement-probe-rows.act] has no declared AgreementMemberData input.',
                $exception->getMessage(),
            );
        }
    }

    public function test_member_collection_admits_two_requirements_and_names_only_the_missing_one(): void
    {
        $resolver = new TwoAgreementAdmissionResolver;
        $this->app->instance(RequirementResolver::class, $resolver);
        $admission = $this->app->make(RequirementAdmission::class);
        $payload = [
            'agreement_members' => [
                ['key' => 'terms', 'version' => 2, 'digest' => 'terms-digest'],
                ['key' => 'privacy', 'version' => 4, 'digest' => 'privacy-digest'],
            ],
        ];
        $members = $admission->submittedMembers(RequirementCollectionInput::class, $payload);

        $this->assertSame(['terms', 'privacy'], array_keys($members));
        $this->assertNull($admission->inspect(null, 'auth.register', null, $members));

        $missingPrivacy = $admission->submittedMembers(RequirementCollectionInput::class, [
            'agreement_members' => [$payload['agreement_members'][0]],
        ]);
        $refusal = $admission->inspect(null, 'auth.register', null, $missingPrivacy);

        $this->assertSame(['privacy'], array_map(fn ($item) => $item->key, $refusal->requirements));
        $this->assertSame(['terms'], array_keys($resolver->lastMembers));
    }

    public function test_duplicate_member_keys_use_the_last_submitted_member(): void
    {
        $members = $this->app->make(RequirementAdmission::class)->submittedMembers(RequirementCollectionInput::class, [
            'agreement_members' => [
                ['key' => 'terms', 'version' => 1, 'digest' => 'old'],
                ['key' => 'terms', 'version' => 2, 'digest' => 'current'],
            ],
        ]);

        $this->assertSame(['terms'], array_keys($members));
        $this->assertEquals(new AgreementMemberData('terms', 2, 'current'), $members['terms']);
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
        $resolver = $this->bindOutstandingResolver();
        $this->mount();
        $this->createProbeRow();

        $this->postJson('/requirement-probe-rows/1/act', [
            'agreement_member' => ['key' => 123, 'version' => 'bad', 'digest' => null],
        ])->assertStatus(428)->assertExactJson([
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

        $this->assertSame([], $resolver->submittedMembers);
    }

    public function test_outstanding_requirement_without_declared_428_fails_loudly(): void
    {
        $this->bindOutstandingResolver();
        $this->mount(declareRefusal: false);
        $this->createProbeRow();
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/requirement-probe-rows/1/act', []);
            $this->fail('an outstanding requirement without errors:428 did not fail loudly');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Operation [requirement-probe-rows.act] has outstanding requirements but does not declare [Splicewire\\Beam\\Agreements\\Data\\RequirementRefusalData] at errors:428.',
                $exception->getMessage(),
            );
        }
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

    private function mount(?string $ability = null, bool $declareRefusal = true): void
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
            errors: $declareRefusal ? [428 => RequirementRefusalData::class] : null,
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

class RequirementProbeRow extends Model
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

class RequirementCollectionInput extends Data
{
    /** @param list<AgreementMemberData> $agreementMembers */
    public function __construct(#[MapInputName('agreement_members'), DataCollectionOf(AgreementMemberData::class)] public array $agreementMembers) {}
}

class TwoAgreementAdmissionResolver implements RequirementResolver
{
    public array $lastMembers = [];

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        $this->lastMembers = $submittedMembers;
        $required = ['terms', 'privacy'];
        $missing = array_diff($required, array_keys($submittedMembers));

        return array_values(array_map(fn (string $key) => new OutstandingRequirementData(
            key: $key,
            version: 1,
            digest: "{$key}-digest",
            kind: 'contract',
            title: ucfirst($key),
            scope: 'party',
            subject: null,
            readUrl: "/agreements/{$key}",
            acceptOperation: null,
            member: 'agreement_members',
        ), $missing));
    }
}

class RequirementProbeOutput extends Data
{
    public function __construct(public bool $ok) {}
}

class ScopedRequirementAdmissionResolver implements RequirementResolver
{
    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        if (isset($submittedMembers['occurrence'])) {
            return [];
        }

        return array_map(fn (string $scope, string $key) => new OutstandingRequirementData(
            key: $key,
            version: 1,
            digest: "{$key}-digest",
            kind: 'contract',
            title: ucfirst($scope),
            scope: $scope,
            subject: null,
            readUrl: "/agreements/{$key}",
            acceptOperation: 'agreements.accept',
            member: null,
        ), ['occurrence', 'subject', 'party', 'organisation'], ['occurrence', 'subject', 'party', 'organisation']);
    }
}

class AmbiguousRequirementProbeInput extends Data
{
    public function __construct(public AgreementMemberData $first, public AgreementMemberData $second) {}
}

class CollectionAndSingleRequirementProbeInput extends Data
{
    public function __construct(
        #[MapInputName('single_agreement_member')] public ?AgreementMemberData $single = null,
        #[MapInputName('agreement_members'), DataCollectionOf(AgreementMemberData::class)] public array $members = [],
    ) {}
}
