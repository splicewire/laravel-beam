<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Facades\DataFilter;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Authorization\ModelLessReadPosture;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetPolicy;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * A resource with no Eloquent model skipped `viewAny` ENTIRELY — at the nav, at frame's socket and at the
 * filter sub-surface — and there was no way to declare otherwise (DESIGN-02, otb-ui-frontier-sidebar).
 *
 * The skip itself is a DECIDED posture, not an accident: app ADR-0119 §2 rules that "service-backed union
 * resources (no model) … skip the check (the API layer still enforces)". What was missing is a spelling
 * for the resource that must NOT be read by everyone its realm admits. {@see ResourceVisibility} gives it
 * one, in a slot that already exists: the declaration's `policy:` ability, which no read path consumed
 * for a model-less resource and no write path can reach for one (frame's `ResourceAuthorizer` refuses a
 * model-less write before any handler runs).
 *
 * ⚠️ Every refusal below is paired with the same fixture ALLOWED, so a gate that denied everything — or a
 * test whose fixture never reached the gate — cannot pass. The undeclared posture is pinned as well,
 * because not reversing a decided ruling is as much the change as the new arm is.
 */
class ModelLessReadGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(ParticleResourceRegistry::class);

        foreach ([
            ['gated-feed', GatedFeedBacking::class, 'feed.read'],
            ['open-feed', GatedFeedBacking::class, null],
            ['class-feed', GatedFeedBacking::class, GadgetPolicy::class],
            ['subject-feed', GatedFeedBacking::class, 'feed.write'],
        ] as [$key, $backing, $policy]) {
            $registry->register(new ParticleResource(
                key: $key,
                backing: $backing,
                data: WidgetGateData::class,
                policy: $policy,
                frame: true,
                readOnly: true,
                showable: false,
            ));
        }

        Gate::define('feed.read', fn (User $user): bool => $user->getAuthIdentifier() === 7);
        // A write-shaped ability: its callback demands a subject a subject-free read cannot hand it.
        Gate::define('feed.write', fn (User $user, object $subject): bool => true);
    }

    // ---- the posture each declaration reads as ---------------------------------------------------

    public function test_each_model_less_declaration_reads_as_exactly_one_posture(): void
    {
        $visibility = $this->app->make(ResourceVisibility::class);
        $registry = $this->app->make(ParticleResourceRegistry::class);

        $this->assertSame(ModelLessReadPosture::DeclaredAbility, $visibility->posture($registry->get('gated-feed')));
        $this->assertSame(ModelLessReadPosture::Undeclared, $visibility->posture($registry->get('open-feed')));
        // The frame projection reads the same posture as the declaration it came from.
        $this->assertSame(ModelLessReadPosture::DeclaredAbility, $visibility->posture($registry->get('gated-feed')->toResourceDefinition()));
        $this->assertSame(ModelLessReadPosture::Undeclared, $visibility->posture($registry->get('open-feed')->toResourceDefinition()));
    }

    public function test_a_model_backed_resource_has_no_model_less_posture(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'modelled',
            backing: BeamSchema::class,
            data: WidgetGateData::class,
            policy: 'feed.read',
        ));

        $visibility = $this->app->make(ResourceVisibility::class);
        $modelled = $this->app->make(ParticleResourceRegistry::class)->get('modelled');

        $this->assertNull($visibility->posture($modelled));
        // Its `policy:` is the WRITE ability PolicyWriteGate reads; it must not start gating reads here.
        $this->assertTrue($visibility->readable($modelled, $this->denied()));
    }

    // ---- frame's socket: the reach gate beam binds ----------------------------------------------

    public function test_the_socket_refuses_a_model_less_resource_to_an_actor_its_declared_ability_denies(): void
    {
        $definition = $this->app->make(ParticleResourceRegistry::class)->get('gated-feed')->toResourceDefinition();

        $this->actingAs($this->denied());
        $this->assertFalse($this->app->make(RealmEntitlementResourceGate::class)->allowsResource($definition));

        $this->actingAs($this->allowed());
        $this->assertTrue($this->app->make(RealmEntitlementResourceGate::class)->allowsResource($definition));
    }

    /**
     * The declared refusal is asked BEFORE the realm arms, so a resource in an ungated realm — the permit
     * arm that returns early — cannot skip it.
     */
    public function test_an_ungated_realm_does_not_skip_the_declared_ability(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->loadRealmMap(['tenant' => ['gated-feed']]);
        $definition = $registry->get('gated-feed')->toResourceDefinition();

        $this->assertSame(['tenant'], $registry->realmsFor('gated-feed'));

        $this->actingAs($this->denied());
        $this->assertFalse($this->app->make(RealmEntitlementResourceGate::class)->allowsResource($definition));

        $this->actingAs($this->allowed());
        $this->assertTrue($this->app->make(RealmEntitlementResourceGate::class)->allowsResource($definition));
    }

    /** A `Gate::before` superuser (the flagship's Root) still reads a declared model-less resource. */
    public function test_a_gate_before_superuser_passes_a_declared_ability(): void
    {
        Gate::before(fn (User $user): ?bool => $user->getAuthIdentifier() === 99 ? true : null);

        $definition = $this->app->make(ParticleResourceRegistry::class)->get('gated-feed')->toResourceDefinition();
        $visibility = $this->app->make(ResourceVisibility::class);

        $this->assertTrue($visibility->readable($definition, (new User)->forceFill(['id' => 99])));
        $this->assertFalse($visibility->readable($definition, $this->denied()));
    }

    /**
     * Two wrong spellings of the ability fail CLOSED and never throw: a policy CLASS-string (no Gate
     * ability) and an ability whose callback demands a subject (an ArgumentCountError inside the Gate).
     * The allowed actor is refused too, which is what proves neither arm fell open.
     */
    public function test_a_misspelled_declared_ability_fails_closed_without_throwing(): void
    {
        $visibility = $this->app->make(ResourceVisibility::class);
        $registry = $this->app->make(ParticleResourceRegistry::class);

        foreach (['class-feed', 'subject-feed'] as $key) {
            $definition = $registry->get($key)->toResourceDefinition();

            $this->assertFalse($visibility->readable($definition, $this->allowed()), $key);
            $this->assertFalse($visibility->listable($definition, $this->allowed()), $key);
        }

        $this->assertTrue($visibility->declaresPolicyClass($registry->get('class-feed')));
        $this->assertFalse($visibility->declaresPolicyClass($registry->get('subject-feed')));
        $this->assertFalse($visibility->declaresPolicyClass($registry->get('gated-feed')));
    }

    /** ADR-0119 §2, pinned: an undeclared model-less resource keeps the posture the ruling decided. */
    public function test_the_socket_still_serves_an_undeclared_model_less_resource(): void
    {
        $definition = $this->app->make(ParticleResourceRegistry::class)->get('open-feed')->toResourceDefinition();

        $this->actingAs($this->denied());
        $this->assertTrue($this->app->make(RealmEntitlementResourceGate::class)->allowsResource($definition));
    }

    // ---- the filter sub-surface ------------------------------------------------------------------

    public function test_the_filter_vocabulary_of_a_model_less_resource_honours_its_declared_ability(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gated-declaring-feed',
            backing: DeclaringGatedFeedBacking::class,
            data: WidgetGateData::class,
            policy: 'feed.read',
            frame: true,
            readOnly: true,
            showable: false,
        ));
        DataFilter::options('gated_feed_sources', fn (?string $search = null) => [['value' => 'a', 'label' => 'A']]);

        // The declaring branch (schema + options) and the declared-empty branch both reach the gate.
        $this->getJson('frame/resources/gated-declaring-feed/filters/schema')->assertForbidden();
        $this->getJson('frame/resources/gated-declaring-feed/filters/options/gated_feed_sources')->assertForbidden();
        $this->getJson('frame/resources/gated-feed/filters/schema')->assertForbidden();

        $this->actingAs($this->denied());
        $this->getJson('frame/resources/gated-declaring-feed/filters/schema')->assertForbidden();
        $this->getJson('frame/resources/gated-feed/filters/schema')->assertForbidden();

        $this->actingAs($this->allowed());
        $this->getJson('frame/resources/gated-declaring-feed/filters/schema')->assertOk();
        $this->getJson('frame/resources/gated-declaring-feed/filters/options/gated_feed_sources')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('frame/resources/gated-feed/filters/schema')->assertOk();
    }

    // ---- the listing answer the nav asks ---------------------------------------------------------

    public function test_listing_hides_a_model_less_resource_its_declared_ability_denies(): void
    {
        $visibility = $this->app->make(ResourceVisibility::class);
        $definition = $this->app->make(ParticleResourceRegistry::class)->get('gated-feed')->toResourceDefinition();

        $this->assertFalse($visibility->listable($definition, $this->denied()));
        $this->assertTrue($visibility->listable($definition, $this->allowed()));
    }

    public function test_listing_never_shows_a_model_less_resource_to_an_anonymous_reader(): void
    {
        $visibility = $this->app->make(ResourceVisibility::class);
        $registry = $this->app->make(ParticleResourceRegistry::class);

        Gate::define('feed.read', fn (?User $user = null): bool => true); // admits even a guest

        foreach (['gated-feed', 'open-feed'] as $key) {
            $this->assertFalse($visibility->listable($registry->get($key)->toResourceDefinition(), null), $key);
            $this->assertTrue($visibility->listable($registry->get($key)->toResourceDefinition(), $this->denied()), $key);
        }
    }

    private function allowed(): User
    {
        return (new User)->forceFill(['id' => 7]);
    }

    private function denied(): User
    {
        return (new User)->forceFill(['id' => 8]);
    }
}

class GatedFeedBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class DeclaringGatedFeedBacking implements DeclaresFilterVocabulary, StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }

    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::set('source', options: 'gated_feed_sources'));
    }
}
