<?php

use Illuminate\Support\Facades\Route;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\Registry\AgreementDefinition;
use Splicewire\Beam\Agreements\Registry\AgreementRegistry;
use Splicewire\Beam\Agreements\Registry\RecordSource;
use Splicewire\Beam\Agreements\Requirements\AgreementLedger;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Agreements\Tests\Fixtures\FixtureNote;
use Splicewire\Beam\Agreements\Tests\Fixtures\User as AgreementTestUser;
use Splicewire\Beam\Agreements\Tests\TestCase as AgreementsTestCase;
use Splicewire\Beam\Authorization\AuthenticatedReadPolicy;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

// Pin the controller from this package before the sibling fixture registers its locked Beam autoloader.
class_exists(ParticleController::class);

// The seam is an installed sibling in this whiffletree space, not a Beam dependency.
require_once dirname(__DIR__, 2).'/../laravel-beam-agreements/vendor/autoload.php';

final class Ag18OperationOutput extends Data
{
    public function __construct(public bool $ok) {}
}

final class Ag18ResolvedData extends Data
{
    public function __construct(public string $id, public string $title) {}
}

final class Ag18ResolvingBacking implements ResolvesRecord
{
    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return $id === 'present'
            ? new ResolvedRecord(new Ag18ResolvedData(id: $id, title: 'Resolved over REST'))
            : null;
    }
}

final class ResolvesRecordRestShowTest extends AgreementsTestCase
{
    private AgreementTestUser $actor;

    private FixtureNote $note;

    protected function setUp(): void
    {
        parent::setUp();

        app(AgreementRegistry::class)->register(new AgreementDefinition(
            'terms',
            'contract',
            'Terms of Service',
            new RecordSource(fn (): string => 'Terms one.'),
        ));
        AgreementLedger::ledger()->freeze(app(AgreementRegistry::class)->get('terms'), 'v1', now()->subDay(), true);

        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'fixture-notes',
            name: 'publish',
            kind: OperationKind::Write,
            model: FixtureNote::class,
            ability: false,
            input: false,
            output: Ag18OperationOutput::class,
            handle: fn (): array => ['ok' => true],
            requires: [new Requires('terms')],
        ));

        Route::middleware(['web', 'auth'])->group(function (): void {
            Particle::ops('fixture-notes', 'fixture-notes', 'publish');
        });

        $this->actor = $this->user('Ada');
        $this->note = FixtureNote::create(['owner_id' => $this->actor->getKey(), 'title' => 'draft']);
    }

    public function test_it_follows_a_real_requirements_refusal_read_url_to_the_agreement_over_rest(): void
    {
        $this->actingAs($this->actor);

        $refusal = $this->postJson('/fixture-notes/'.$this->note->getKey().'/publish')->assertStatus(428);
        $readUrl = $refusal->json('requirements.0.readUrl');

        $this->assertSame($this->agreementsUrl('agreements/terms'), $readUrl);

        $this->getJson(parse_url($readUrl, PHP_URL_PATH))
            ->assertOk()
            ->assertJsonPath('data.id', 'terms')
            ->assertJsonPath('data.key', 'terms')
            ->assertJsonPath('data.title', 'Terms of Service');
    }

    public function test_it_serves_a_resolves_record_only_backing_over_rest_with_frame_read_authorization_and_404_semantics(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'resolved-records',
            backing: Ag18ResolvingBacking::class,
            data: Ag18ResolvedData::class,
            input: false,
            readOnly: true,
            readPolicy: AuthenticatedReadPolicy::class,
        ));

        Route::get('/resolved-records/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'resolved-records');

        $this->getJson('/resolved-records/present')->assertForbidden();

        $this->actingAs($this->actor);
        $this->getJson('/resolved-records/present')
            ->assertOk()
            ->assertJsonPath('data.id', 'present')
            ->assertJsonPath('data.title', 'Resolved over REST');
        $this->getJson('/resolved-records/missing')->assertNotFound();
    }
}
