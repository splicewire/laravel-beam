<?php

namespace Splicewire\Beam\Tests\Events;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Events\BeamEvent;
use Splicewire\Beam\Events\BeamEventRegistrar;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\ParticlePersistedEventRegistrar;
use Splicewire\Beam\Events\ResourceKeyOracle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The publishable-event catalog (api-surface-coherence ticket 40).
 *
 * ## The totality guard, and its blind spot
 *
 * {@see test_every_registered_event_type_declares_a_subject} is ticket 13 §7's shape: TOTALITY over the
 * catalog plus a NAMED allowlist of the events that ship subject-less, and that allowlist is empty. It
 * is a shrinking allowlist (ticket 29's precedent), never a count ratchet — a count says nothing about
 * WHICH event lost its subject, and it ratchets in the wrong direction the moment the catalog grows.
 *
 * ⚠️ **Its discovery blind spot, stated the way `ApiGroupCoverageTest` states its own:** this test walks
 * the registry as populated in THIS harness. Beam's own boot fills it from beam's own particle
 * resources, which in a package test are whatever a test registered — so this file proves the RULE holds
 * over whatever is registered; it does not prove the flagship host's catalog is complete. A host that
 * registers an event type from its own provider and never runs this assertion is invisible here. The
 * host-side guard is the same assertion re-run against the booted host, which is what makes it worth
 * keeping the rule in the registry (where it throws at registration) rather than only in a test.
 */
class EventTypeCatalogTest extends TestCase
{
    /**
     * The events that legitimately have no subject.
     *
     * EMPTY, and adding the first entry is a decision, not a build step (ticket 13 §5). Every name here
     * must also carry `subjectless: true` on its own declaration — the allowlist does not grant the
     * exemption, it records that someone argued for one.
     *
     * @var list<string>
     */
    public const SUBJECTLESS = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: BeamParticle::class,
            frame: false,
        ));
    }

    private function registry(): EventTypeRegistry
    {
        return new EventTypeRegistry(new ResourceKeyOracle($this->app));
    }

    // ── The registry itself ─────────────────────────────────────────────────────────────────────────

    public function test_it_is_enumerable_without_booting_http(): void
    {
        $registry = $this->registry();

        $registry->register(new EventType('widgets.provisioned', subject: BeamParticle::class));
        $registry->register(new EventType('widgets.render.completed', subject: BeamParticle::class));

        $this->assertSame(
            ['widgets.provisioned', 'widgets.render.completed'],
            $registry->names(),
        );

        // Data, not computation: `all()` hands back what was registered, in registration order, so
        // ticket 13 §7's totality assertion guards the catalog rather than a generator.
        $this->assertContainsOnlyInstancesOf(EventType::class, $registry->all());
    }

    public function test_the_resource_key_is_segment_one_and_the_verb_phrase_may_be_multi_segment(): void
    {
        $type = new EventType('widgets.render.completed', subject: BeamParticle::class);

        $this->assertSame('widgets', $type->resourceKey());
        $this->assertSame('render.completed', $type->verbPhrase());
    }

    public function test_with_prefix_is_a_branch_read_not_a_string_prefix_test(): void
    {
        $registry = $this->registry();
        $registry->register(new EventType('widgets.render.completed', subject: BeamParticle::class));

        $this->assertSame(
            ['widgets.render.completed'],
            array_map(fn (EventType $t) => $t->name, $registry->withPrefix('widgets')),
        );

        // The trap a `str_starts_with` implementation falls into: `widget` is not a prefix of the
        // SEGMENTS of `widgets.render.completed`, however much the strings suggest otherwise.
        $this->assertSame([], $registry->withPrefix('widget'));
    }

    public function test_find_answers_null_for_a_name_that_is_not_even_a_legal_key(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->find('Not A Key'));
        $this->assertFalse($registry->has('Not A Key'));
    }

    // ── Registration-time validation, rejecting loudly ──────────────────────────────────────────────

    public function test_a_name_that_does_not_match_the_grammar_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a legal event type name');

        // One segment: no verb phrase at all.
        $this->registry()->register(new EventType('widgets', subject: BeamParticle::class));
    }

    /**
     * The correction of api-surface-coherence ticket 91, and the reason it is a test rather than a note.
     *
     * This assertion was `expectException('which is not registered anywhere')`. As a throw the check took
     * `~/Herd/tower` off the air — every `artisan` invocation, `--version` included — because tower
     * declares `compositions.generate.*` and registers no `compositions` resource. "Is this prefix live?"
     * is a fact about the HOST; the other three checks are facts about the declaration. Only the
     * host-dependent one had to stop being fatal.
     *
     * Registering it rather than dropping it is the other half: refusing the entry would amputate a
     * host's own declared vocabulary silently, which is worse than recording it and reporting it.
     */
    public function test_a_prefix_that_is_not_a_live_resource_key_is_recorded_and_reported_rather_than_rejected(): void
    {
        $registry = $this->registry();

        $registry->register(new EventType('sprockets.provisioned', subject: BeamParticle::class));

        $this->assertSame(['sprockets.provisioned'], $registry->names());
        $this->assertSame(['sprockets.provisioned' => 'sprockets'], $registry->unresolvedPrefixes());
    }

    public function test_a_live_prefix_produces_no_advisory(): void
    {
        $registry = $this->registry();

        $registry->register(new EventType('widgets.provisioned', subject: BeamParticle::class));

        $this->assertSame([], $registry->unresolvedPrefixes());
        $this->assertContains('widgets', $registry->knownResourceKeys());
    }

    /**
     * The reason the advisory is computed on READ and never stamped at `register()`: a resource declared
     * after the event that names it is the ordinary multi-package boot order, and a flag taken at
     * registration would report load order rather than truth.
     */
    public function test_a_resource_registered_after_the_event_clears_the_advisory(): void
    {
        $registry = $this->registry();

        $registry->register(new EventType('sprockets.provisioned', subject: BeamParticle::class));
        $this->assertSame(['sprockets.provisioned' => 'sprockets'], $registry->unresolvedPrefixes());

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'sprockets',
            backing: BeamParticle::class,
            frame: false,
        ));

        $this->assertSame([], $registry->unresolvedPrefixes());
    }

    public function test_an_entry_with_no_subject_and_no_subjectless_declaration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares no subject');

        $this->registry()->register(new EventType('widgets.provisioned'));
    }

    public function test_subjectless_is_the_one_escape_hatch_and_it_works(): void
    {
        $registry = $this->registry();
        $registry->register(new EventType('widgets.swept', subjectless: true));

        $this->assertSame(['widgets.swept'], $registry->names());
    }

    public function test_an_undeclared_payload_is_recorded_rather_than_rejected(): void
    {
        $registry = $this->registry();
        $registry->register(new EventType('widgets.provisioned', subject: BeamParticle::class));

        $this->assertNull($registry->find('widgets.provisioned')?->payload);
    }

    public function test_two_declarations_of_one_name_are_rejected_rather_than_superseded(): void
    {
        $registry = $this->registry();
        $registry->register(new EventType('widgets.provisioned', subject: BeamParticle::class));

        $this->expectException(DuplicateRegistryKey::class);

        $registry->register(new EventType('widgets.provisioned', subject: BeamParticle::class));
    }

    // ── #[BeamEvent] is a feeder, not a second seam ─────────────────────────────────────────────────

    public function test_the_attribute_feeds_register_rather_than_holding_state(): void
    {
        $registry = $this->registry();
        $registry->attach(new BeamEventRegistrar([], [WidgetSwept::class]));

        $this->assertSame(['widgets.swept', 'widgets.reswept'], $registry->names());

        // The proof that it is a feeder: the entries are in the ACCUMULATOR, indistinguishable from a
        // hand-registered one, and the registrar is recorded as their source.
        $this->assertInstanceOf(EventType::class, $registry->find('widgets.swept'));
        $this->assertCount(1, $registry->registrars());
    }

    /**
     * The attribute is a feeder onto `register()`, so it inherits the throwing checks. Asserted against
     * the missing-subject one: the prefix check stopped throwing in ticket 91, so it can no longer serve
     * as the visible consequence here.
     */
    public function test_the_attribute_gets_no_exemption_from_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares no subject');

        $this->registry()->attach(new BeamEventRegistrar([], [SubjectlessUndeclared::class]));
    }

    /** …and it inherits the ADVISORY one too — the attribute buys no exemption from being reported. */
    public function test_the_attribute_carries_a_dead_prefix_into_the_advisory(): void
    {
        $registry = $this->registry();
        $registry->attach(new BeamEventRegistrar([], [SprocketSwept::class]));

        $this->assertSame(['sprockets.swept' => 'sprockets'], $registry->unresolvedPrefixes());
    }

    /**
     * ⚠️ The delegation trap, measured on `ParticleResourceRegistry` first and re-asserted here:
     * `BasicRegistry::attach()` hands the registrar the STORE, so an owner that delegates `attach()`
     * silently routes every registrar write past its own `register()` — and past every validation above.
     * This asserts the owner attached to ITSELF, by the only externally visible consequence: a registrar
     * write that should have been rejected IS rejected.
     */
    public function test_a_registrar_writes_through_the_owners_register_not_the_store(): void
    {
        $registry = $this->registry();

        try {
            $registry->attach(new BeamEventRegistrar([], [SubjectlessUndeclared::class]));
            $this->fail('A registrar wrote past the owner\'s register() — the delegation trap is live.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('declares no subject', $e->getMessage());
        }
    }

    // ── The BeamParticlePersisted fan-out ───────────────────────────────────────────────────────────

    public function test_beam_particle_persisted_expands_per_particle_resource_at_registration(): void
    {
        $registry = $this->registry();
        $registry->attach(new ParticlePersistedEventRegistrar(app(ParticleResourceRegistry::class)));

        $this->assertContains('widgets.persisted', $registry->names());
        $this->assertSame(BeamParticle::class, $registry->find('widgets.persisted')?->subject);
    }

    public function test_a_resource_with_no_model_class_is_skipped_rather_than_declared_subjectless(): void
    {
        // `members`/`review-queue` in the flagship host are the live shape: a backing that resolves to no
        // single model. Declaring them subjectless would put the first entry on an allowlist that ships
        // empty; skipping keeps both the allowlist and the catalog honest.
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'modelless',
            backing: new UnionBacking,
            readOnly: true,
            frame: false,
        ));

        $registry = $this->registry();
        $registry->attach(new ParticlePersistedEventRegistrar(app(ParticleResourceRegistry::class)));

        $this->assertNotContains('modelless.persisted', $registry->names());
    }

    // ── Declaration + index membership (the successor to "describe into ManifestIndex") ─────────────

    public function test_it_declares_itself_and_is_reachable_through_the_index(): void
    {
        $declaration = IsRegistry::of(EventTypeRegistry::class);

        $this->assertNotNull($declaration);
        $this->assertSame('beam.events.types', $declaration->root);
        $this->assertSame('beam.events.types', (string) Key::parse($declaration->root));
        $this->assertNotSame('', $declaration->of);
        $this->assertSame(EventType::class, $declaration->entryType);

        // ⚠️ The harness trap: without `PopcornServiceProvider` in getPackageProviders() every `make()`
        // hands back a FRESH RegistryIndex, and this assertion passes against an object nobody reads.
        // `Splicewire\Beam\Tests\TestCase` does register it — asserting the singleton identity here so a
        // future harness edit that drops it fails HERE rather than silently going green.
        $this->assertSame($this->app->make(RegistryIndex::class), $this->app->make(RegistryIndex::class));

        $this->assertNotNull(
            $this->app->make(RegistryIndex::class)->routeTo('beam.events.types'),
            'beam.events.types is declared but not indexed — BeamServiceProvider::boot() must describe it.',
        );
    }

    // ── The totality guard ──────────────────────────────────────────────────────────────────────────

    public function test_every_registered_event_type_declares_a_subject(): void
    {
        $registry = $this->app->make(EventTypeRegistry::class);

        $offenders = [];

        foreach ($registry->all() as $type) {
            if ($type->subject !== null && $type->subject !== '') {
                continue;
            }

            if (! in_array($type->name, self::SUBJECTLESS, true)) {
                $offenders[] = $type->name;
            }
        }

        $this->assertSame([], $offenders, 'Event types with no subject and no allowlist entry.');
        $this->assertSame([], self::SUBJECTLESS, 'The subjectless allowlist ships empty and only shrinks.');
    }
}

#[BeamEvent('widgets.swept', subject: BeamParticle::class)]
#[BeamEvent('widgets.reswept', subject: BeamParticle::class)]
class WidgetSwept {}

#[BeamEvent('sprockets.swept', subject: BeamParticle::class)]
class SprocketSwept {}

#[BeamEvent('widgets.unsubjected')]
class SubjectlessUndeclared {}

/**
 * A backing that resolves to no single model — the `members`/`review-queue` shape. It implements
 * {@see ResourceBacking} and deliberately NOT {@see BacksModel},
 * which is precisely the case `modelClass()` answers null for.
 */
class UnionBacking implements ResourceBacking {}
