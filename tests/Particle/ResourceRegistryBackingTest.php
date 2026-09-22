<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Rushing\DataFilters\Facades\DataFilter;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\ResourceRegistry\ResourceRegistryEntryData;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Registry\RealmResourceSurfaceLocator;
use Splicewire\Beam\Particle\Registry\ResourceRegistryBacking;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Particle\ResourceRegistryRow;
use Splicewire\Beam\Realm\Contracts\TenantResolver;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The operator Resources area's backing (otb-ui-frontier-sidebar DESIGN-02): the registry served as rows,
 * filtered to what the actor may LIST.
 *
 * ⚠️ Every absence below is paired with the same fixture PRESENT for an actor the gate admits, so a
 * backing that returned nothing — or a fixture that never reached the gate — cannot pass. The population
 * is a fresh registry, never the harness's booted one, so a count asserts exactly the declarations
 * written here.
 */
class ResourceRegistryBackingTest extends TestCase
{
    private ParticleResourceRegistry $registry;

    private ?User $actor = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ParticleResourceRegistry;

        // Model-backed with a bound `viewAny` that admits only actor 7.
        Gate::policy(Gadget::class, StrangerRefusingGadgetPolicy::class);
        $this->registry->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: WidgetGateData::class,
            label: 'Gadgets',
            section: 'platform',
        ), ['operator']);

        // Model-less, gated by a declared subject-free ability only actor 7 holds.
        Gate::define('feed.read', fn (User $user): bool => $user->getAuthIdentifier() === 7);
        $this->registry->register(new ParticleResource(
            key: 'gated-feed',
            backing: RegistryFeedBacking::class,
            data: WidgetGateData::class,
            label: 'Gated feed',
            policy: 'feed.read',
            readOnly: true,
        ), ['tenant']);

        // Model-less, undeclared: ADR-0119 §2's posture — any authenticated actor, never a guest.
        // This stream cannot resolve a detail, so its default showable claim is a disagreement.
        $this->registry->register(new ParticleResource(
            key: 'open-feed',
            backing: RegistryStreamBacking::class,
            data: WidgetGateData::class,
            readOnly: true,
        ));

        // Model-less, a policy CLASS where an ability belongs — fails closed for everyone.
        $this->registry->register(new ParticleResource(
            key: 'class-feed',
            backing: RegistryFeedBacking::class,
            data: WidgetGateData::class,
            label: 'Class feed',
            section: 'ops',
            policy: StrangerRefusingGadgetPolicy::class,
            readOnly: true,
        ));
    }

    // ---- the row filter is the gate --------------------------------------------------------------

    public function test_a_resource_whose_viewany_denies_the_actor_is_absent_and_present_for_one_it_admits(): void
    {
        $this->actor = $this->stranger();
        $this->assertNotContains('gadgets', $this->keys());

        $this->actor = $this->member();
        $this->assertContains('gadgets', $this->keys());
    }

    public function test_a_model_less_resource_is_absent_to_an_actor_its_declared_ability_denies(): void
    {
        $this->actor = $this->stranger();
        $this->assertNotContains('gated-feed', $this->keys());

        $this->actor = $this->member();
        $this->assertContains('gated-feed', $this->keys());
    }

    public function test_an_undeclared_model_less_resource_is_absent_for_a_null_actor_and_listed_to_an_authenticated_one(): void
    {
        $this->actor = null;
        $this->assertNotContains('open-feed', $this->keys());

        $this->actor = $this->stranger();
        $this->assertContains('open-feed', $this->keys());
    }

    public function test_a_null_actor_sees_nothing_gated_at_all(): void
    {
        $this->actor = null;

        $this->assertSame([], $this->keys());
    }

    public function test_a_policy_class_spelling_hides_the_resource_from_every_non_superuser(): void
    {
        $this->actor = $this->member();
        $this->assertNotContains('class-feed', $this->keys());

        Gate::before(fn (User $user): ?bool => $user->getAuthIdentifier() === 99 ? true : null);
        $this->actor = (new User)->forceFill(['id' => 99]);
        $this->assertContains('class-feed', $this->keys());
    }

    /**
     * A REST-only declaration with no `data:` class has no frame projection (`toResourceDefinition()` refuses
     * it), but it is still a registered resource with a posture: it must be CLASSIFIED, not dropped. Measured
     * at the flagship 2026-09-12: three such resources vanished for Root until the gate stopped asking the
     * frame projection.
     */
    public function test_a_resource_with_no_read_data_class_is_still_classified_and_listed(): void
    {
        $this->registry->register(new ParticleResource(key: 'dataless', backing: Gadget::class));

        $this->actor = $this->stranger();
        $this->assertNotContains('dataless', $this->keys());   // the bound viewAny still refuses

        $this->actor = $this->member();
        $this->assertContains('dataless', $this->keys());
    }

    public function test_a_backing_that_cannot_be_resolved_for_this_request_is_absent_even_to_a_superuser(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->registry->register(new ParticleResource(
            key: 'unresolvable',
            backing: UnresolvableModelBacking::class,
            data: WidgetGateData::class,
            readOnly: true,
        ));

        $this->actor = $this->member();
        $keys = $this->keys();

        $this->assertNotContains('unresolvable', $keys);
        $this->assertContains('gadgets', $keys);
    }

    public function test_a_detail_read_of_an_unlistable_key_is_null_not_a_refusal(): void
    {
        $this->actor = $this->stranger();
        $this->assertNull($this->backing()->resolve('gadgets', []));

        $this->actor = $this->member();
        $resolved = $this->backing()->resolve('gadgets', []);
        $this->assertInstanceOf(ResolvedRecord::class, $resolved);
        $this->assertSame('gadgets', $resolved->record->key);
    }

    public function test_an_unknown_key_resolves_to_null(): void
    {
        $this->actor = $this->member();

        $this->assertNull($this->backing()->resolve('no-such-resource', []));
    }

    // ---- what a row says ---------------------------------------------------------------------------

    public function test_a_row_carries_posture_and_the_read_gate_finding_for_its_resource(): void
    {
        $this->actor = $this->member();

        $entries = $this->byKey();

        $this->assertSame('model-backed', $entries['gadgets']->posture);
        $this->assertSame([], $entries['gadgets']->readGateFindings);
        $this->assertSame('declared-ability', $entries['gated-feed']->posture);
        $this->assertSame([], $entries['gated-feed']->readGateFindings);
        $this->assertSame('undeclared', $entries['open-feed']->posture);
        $this->assertCount(1, $entries['open-feed']->readGateFindings);
        $this->assertStringStartsWith('particle.model-less-read-gate', $entries['open-feed']->readGateFindings[0]);

        // The policy-class finding needs a reader that can SEE the row — the superuser.
        Gate::before(fn (User $user): ?bool => $user->getAuthIdentifier() === 99 ? true : null);
        $this->actor = (new User)->forceFill(['id' => 99]);
        $finding = $this->byKey()['class-feed']->readGateFindings;
        $this->assertCount(1, $finding);
        $this->assertStringContainsString('policy CLASS', $finding[0]);
    }

    public function test_a_row_reports_the_realm_facts_of_each_surface(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->actor = $this->member();
        $this->registry->register(new ParticleResource(
            key: 'account-tokens',
            backing: Gadget::class,
            data: WidgetGateData::class,
            label: 'Tokens',
            readOnly: true,
        ), ['user', 'tenant']);

        $entries = $this->byKey();

        [$operator] = $entries['gadgets']->surfaces;
        $this->assertSame('operator', $operator->realm);
        $this->assertTrue($operator->central);
        $this->assertFalse($operator->requiresTenant);
        $this->assertFalse($operator->actorScoped);

        [$tenant] = $entries['gated-feed']->surfaces;
        $this->assertFalse($tenant->central);
        $this->assertTrue($tenant->requiresTenant);

        // The account realm composes through `tenant` on a collapsed deployment, so it needs a tenant too —
        // and its records are the actor's own.
        [$user, $tenantToo] = $entries['account-tokens']->surfaces;
        $this->assertSame('user', $user->realm);
        $this->assertTrue($user->actorScoped);
        $this->assertTrue($user->requiresTenant);
        $this->assertFalse($tenantToo->actorScoped);

        // Homed in no realm ⇒ no surface, stated as an empty list rather than guessed.
        $this->assertSame([], $entries['open-feed']->surfaces);
        $this->assertFalse($entries['open-feed']->navSeated);
    }

    public function test_rows_are_ordered_by_section_with_the_unsectioned_group_last(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->actor = $this->member();

        $this->assertSame(['class-feed', 'gadgets', 'gated-feed', 'open-feed'], $this->keys());
    }

    // ---- effective affordances: capability ∩ intent ---------------------------------------------

    public function test_effective_affordances_are_capability_intersected_with_intent(): void
    {
        // A declaration saying `editable` over a backing that cannot write: the edit affordance is closed.
        $overclaiming = $this->row(writes: false, creatable: false, editable: true, deletable: true, showable: true);
        $this->assertSame(
            ['list' => true, 'show' => false, 'create' => false, 'edit' => false, 'delete' => false, 'filter' => false],
            $overclaiming->affordances(),
        );

        // A writing backing declared read-only: narrowing is the mechanism working, not a gap.
        $narrowed = $this->row(writes: true, queries: true, creatable: false, editable: false, deletable: false, showable: true);
        $this->assertSame(
            ['list' => true, 'show' => true, 'create' => false, 'edit' => false, 'delete' => false, 'filter' => false],
            $narrowed->affordances(),
        );

        // Both halves open ⇒ open. A declared vocabulary is a panel with no `filterable` flag.
        $open = $this->row(writes: true, resolves: true, vocabulary: true, filters: true, creatable: true, editable: true, deletable: true, showable: true);
        $this->assertSame(
            ['list' => true, 'show' => true, 'create' => true, 'edit' => true, 'delete' => true, 'filter' => true],
            $open->affordances(),
        );
    }

    public function test_registration_refuses_an_editable_declaration_over_a_non_writing_backing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capability is the ceiling');

        (new ParticleResourceRegistry)->register(new ParticleResource(
            key: 'resources',
            backing: ResourceRegistryBacking::class,
            data: ResourceRegistryEntryData::class,
            editable: true,
            readOnly: true,
        ));
    }

    public function test_the_served_row_carries_the_effective_affordances(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->actor = $this->member();

        $gadgets = $this->byKey()['gadgets'];
        $feed = $this->byKey()['gated-feed'];

        $this->assertTrue($gadgets->affordances->edit);
        $this->assertTrue($gadgets->affordances->create);
        $this->assertFalse($feed->affordances->edit);
        $this->assertFalse($feed->affordances->create);
        $this->assertTrue($feed->affordances->show);   // it resolves one record
        $this->assertFalse($feed->affordances->filter); // no query, no vocabulary
    }

    // ---- the declared facets, applied -------------------------------------------------------------

    public function test_every_declared_facet_is_applied(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->actor = $this->member();

        $this->assertSame(['gated-feed', 'open-feed'], $this->keys(['section' => 'none']));
        $this->assertSame(['class-feed', 'gadgets'], $this->keys(['section' => 'platform,ops']));
        $this->assertSame(['gated-feed'], $this->keys(['realm' => 'tenant']));
        $this->assertSame(['class-feed', 'open-feed'], $this->keys(['realm' => 'none']));
        // ALL-of: resolves AND streams narrows to the model-less backings; adding writes empties it.
        $this->assertSame(['class-feed', 'gated-feed'], $this->keys(['capability' => 'streams,resolves']));
        $this->assertSame([], $this->keys(['capability' => 'resolves,writes']));
        $this->assertSame([], $this->keys(['capability' => 'not-a-capability']));
        $this->assertSame(['open-feed'], $this->keys(['posture' => 'undeclared']));
        $this->assertSame(['gadgets'], $this->keys(['posture' => 'model-backed']));
        $this->assertSame(['gadgets'], $this->keys(['search' => 'gadget']));
        $this->assertSame(['class-feed', 'gadgets', 'gated-feed', 'open-feed'], $this->keys(['nav' => 'unseated']));
        $this->assertSame([], $this->keys(['nav' => 'seated']));
        $this->assertSame([], $this->keys(['search' => 'gadget', 'section' => 'ops']));

        $vocabulary = $this->backing()->filterVocabulary()->names();
        $this->assertSame(['search', 'section', 'realm', 'capability', 'posture', 'nav', 'disagreement'], $vocabulary);
    }

    public function test_a_disagreement_facet_selects_intent_exceeding_capability(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->actor = $this->member();

        // Only open-feed promises details without a backing capable of resolving them.
        $this->assertSame(['open-feed'], $this->keys(['disagreement' => 'some']));
        $this->assertSame(['class-feed', 'gadgets', 'gated-feed'], $this->keys(['disagreement' => 'none']));
        $this->assertNotSame([], $this->byKey()['open-feed']->disagreements);
    }

    public function test_option_domains_offer_only_what_the_actor_can_see(): void
    {
        $this->registerArea(policy: null);
        $this->actor = $this->stranger();

        // The stranger lists `open-feed` (no section, no realm) and the undeclared area itself (operator) —
        // `platform`, `ops` and `tenant` live on rows it cannot see and must not be offered.
        $this->assertSame(['none'], array_column($this->backing()->options('section'), 'value'));
        $this->assertSame(['operator', 'none'], array_column($this->backing()->options('realm'), 'value'));

        $this->actor = $this->member();
        $this->assertSame(['platform', 'none'], array_column($this->backing()->options('section'), 'value'));
        $this->assertSame(['platform'], array_column($this->backing()->options('section', 'plat'), 'value'));
        $this->assertSame([], $this->backing()->options('not-a-facet'));
    }

    /**
     * The Options Sources are reachable from filter mounts that never ask the area's read gate (data-filters'
     * options namespace is flat), so the domains ask it themselves: an actor refused the area gets nothing,
     * through the REGISTERED handle, and the same actor admitted gets the domain.
     */
    public function test_option_domains_are_empty_to_an_actor_the_area_refuses_even_through_the_registered_handle(): void
    {
        $this->registerArea(policy: 'feed.read');
        $this->app->bind(ResourceRegistryBacking::class, fn () => $this->backing());

        $this->actor = $this->stranger();
        $this->assertSame([], DataFilter::resolveOptions(ResourceRegistryBacking::OPTIONS['capability']));
        $this->assertSame([], DataFilter::resolveOptions(ResourceRegistryBacking::OPTIONS['realm']));

        $this->actor = $this->member();
        $this->assertCount(5, DataFilter::resolveOptions(ResourceRegistryBacking::OPTIONS['capability']));
        $this->assertSame(['operator', 'tenant', 'none'], array_column(DataFilter::resolveOptions(ResourceRegistryBacking::OPTIONS['realm']), 'value'));
        // And the handle resolves the facet it is named for, not a neighbour's.
        $this->assertSame(['seated', 'unseated'], array_column(DataFilter::resolveOptions(ResourceRegistryBacking::OPTIONS['nav']), 'value'));
    }

    /**
     * Every picker facet names an Options Source, and beam registers each handle — the panel resolves a
     * picker's options through `optionsRef` alone, so a facet whose handle nothing registered renders an
     * empty picker.
     */
    public function test_every_picker_facet_names_an_options_source_beam_registers(): void
    {
        $vocabulary = $this->backing()->filterVocabulary();

        foreach (ResourceRegistryBacking::OPTIONS as $facet => $handle) {
            $this->assertTrue($vocabulary->references($handle), $facet);
            $this->assertTrue(DataFilter::hasOptions($handle), $handle);
        }
    }

    public function test_records_page_by_key_cursor(): void
    {
        Gate::before(fn (User $user): ?bool => true);
        $this->actor = $this->member();

        $first = $this->backing()->records([], null, 2);
        $this->assertSame(['class-feed', 'gadgets'], array_map(fn ($entry) => $entry->key, $first->items()));
        $this->assertTrue($first->hasMorePages());

        $second = $this->backing()->records([], $first->nextCursor()?->encode(), 2);
        $this->assertSame(['gated-feed', 'open-feed'], array_map(fn ($entry) => $entry->key, $second->items()));
        $this->assertFalse($second->hasMorePages());

        // Back again: the previous-page cursor reads the rows BEFORE the key, in order.
        $back = $this->backing()->records([], $second->previousCursor()?->encode(), 2);
        $this->assertSame(['class-feed', 'gadgets'], array_map(fn ($entry) => $entry->key, $back->items()));
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /** Declare the area itself over this backing, as a host would, with the given read gate. */
    private function registerArea(?string $policy): void
    {
        $this->registry->register(new ParticleResource(
            key: 'resources',
            backing: ResourceRegistryBacking::class,
            data: ResourceRegistryEntryData::class,
            input: false,
            label: 'Resources',
            policy: $policy,
            readOnly: true,
        ), ['operator']);
    }

    private function backing(): ResourceRegistryBacking
    {
        $visibility = new ResourceVisibility($this->registry, $this->app->make(\Illuminate\Contracts\Auth\Access\Gate::class));
        $actor = $this->actor;

        return new ResourceRegistryBacking(
            new ResourceRegistryReport($this->registry),
            $this->registry,
            $visibility,
            new RealmResourceSurfaceLocator($this->registry, $this->app->make(RealmRegistry::class), $this->app->make(TenantResolver::class)),
            new class($actor) implements ActorPort
            {
                public function __construct(private ?User $actor) {}

                public function actor(): mixed
                {
                    return $this->actor;
                }
            },
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function keys(array $filters = []): array
    {
        return array_map(fn (ResourceRegistryEntryData $entry) => $entry->key, $this->backing()->records($filters, null, 100)->items());
    }

    /** @return array<string, ResourceRegistryEntryData> */
    private function byKey(): array
    {
        $entries = [];

        foreach ($this->backing()->entries() as $entry) {
            $entries[$entry->key] = $entry;
        }

        return $entries;
    }

    private function row(
        bool $writes = false,
        bool $queries = false,
        bool $resolves = false,
        bool $vocabulary = false,
        bool $creatable = false,
        bool $editable = false,
        bool $deletable = false,
        bool $showable = true,
        bool $filters = false,
    ): ResourceRegistryRow {
        return new ResourceRegistryRow(
            key: 'probe',
            label: 'Probe',
            realms: [],
            section: null,
            framed: true,
            backing: RegistryFeedBacking::class,
            model: null,
            handler: null,
            streams: true,
            queries: $queries,
            resolves: $resolves,
            writes: $writes,
            vocabulary: $vocabulary,
            readOnly: ! $creatable,
            creatable: $creatable,
            editable: $editable,
            deletable: $deletable,
            showable: $showable,
            filters: $filters,
            policy: null,
            disagreements: [],
        );
    }

    private function member(): User
    {
        return (new User)->forceFill(['id' => 7]);
    }

    private function stranger(): User
    {
        return (new User)->forceFill(['id' => 8]);
    }
}

class StrangerRefusingGadgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->getAuthIdentifier() === 7;
    }
}

class RegistryFeedBacking implements ResolvesRecord, StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return null;
    }
}

class UnresolvableModelBacking implements BacksModel, StreamsRecords
{
    public function __construct()
    {
        throw new \RuntimeException('needs a tenant connection this request has not entered');
    }

    public function modelClass(): string
    {
        return Gadget::class;
    }

    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class RegistryStreamBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}
