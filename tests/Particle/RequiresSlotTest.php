<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\AgreementKeyResolver;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Agreements\NullRequirementDeclarations;
use Splicewire\Beam\Agreements\NullRequirementResolver;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Agreements\SlotRequirementDeclarations;
use Splicewire\Beam\Agreements\SlotRequirementResolver;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Doctor\UnseamedRequirementSlotAudit;
use Splicewire\Beam\Entitlements\CanMapBuilder;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

/**
 * agreements 05 (ADR-0247 amendment): the `requires:` slot on operations and resources, beam's slot defaults (no
 * seam ⇒ every caller refused as an `unregistered` Agreement; no slot ⇒ unchanged), resource admission after the
 * verb's ability on REST and frame, and the readers — route manifest, ability map, generated types, doctor.
 */
class RequiresSlotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('requires_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        RequiresRowPolicy::$allow = true;
        Gate::policy(RequiresRow::class, RequiresRowPolicy::class);
    }

    // ── operations ───────────────────────────────────────────────────────────────────────────────────────────────

    public function test_an_op_with_a_requires_slot_and_no_seam_refuses_with_one_unregistered_item(): void
    {
        $this->resource();
        $operation = $this->op(requires: [new Requires('terms')]);
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->assertSame([428 => RequirementRefusalData::class], $operation->declaredErrors());

        $this->postJson('/requires-rows/1/act', [])->assertStatus(428)->assertExactJson(['requirements' => [[
            'key' => 'terms',
            'version' => 0,
            'digest' => '',
            'kind' => 'unregistered',
            'title' => 'terms',
            'scope' => 'party',
            'subject' => null,
            'readUrl' => '',
            'acceptOperation' => null,
            'member' => null,
        ]]]);

        $finding = $this->audit();
        $this->assertSame('fail', $finding->status->value);
        $this->assertStringContainsString('requires-rows.act', $finding->detail);
        $this->assertStringContainsString('terms', $finding->detail);
        $this->assertStringContainsString('unknown Agreement', $finding->detail);
    }

    public function test_a_subject_aware_requirement_names_the_key_resolved_for_its_subject(): void
    {
        RequiresRowAgreementKeyResolver::$resolvedSubjects = [];
        $this->resource();
        $this->op(
            requires: [new Requires(resolver: RequiresRowAgreementKeyResolver::class, scope: 'occurrence')],
            input: RequiresRowMemberInput::class,
        );
        RequiresRow::query()->create(['name' => 'plan']);
        RequiresRow::query()->create(['name' => 'second plan']);
        $this->actingAs($this->actor());

        $this->postJson('/requires-rows/1/act', [])->assertStatus(428)
            ->assertJsonPath('requirements.0.key', 'plans.1.terms');
        $this->postJson('/requires-rows/2/act', [])->assertStatus(428)
            ->assertJsonPath('requirements.0.key', 'plans.2.terms')
            ->assertJsonPath('requirements.0.kind', 'unregistered');

        $this->assertSame([
            [RequiresRow::class, 1],
            [RequiresRow::class, 2],
        ], RequiresRowAgreementKeyResolver::$resolvedSubjects);
    }

    public function test_a_subject_aware_requirement_refuses_a_subject_free_operation_at_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subject resolver yields no model');

        $this->op(
            requires: [new Requires(resolver: RequiresRowAgreementKeyResolver::class, scope: 'occurrence')],
            input: RequiresRowMemberInput::class,
            subject: NoSubject::class,
        );
    }

    public function test_a_subject_aware_requirement_does_not_call_its_resolver_without_a_model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a model subject');

        (new Requires(resolver: RequiresRowAgreementKeyResolver::class))->agreementFor(null);
    }

    public function test_requires_demands_exactly_one_of_fixed_key_or_resolver(): void
    {
        foreach ([
            fn () => new Requires,
            fn () => new Requires('terms', resolver: RequiresRowAgreementKeyResolver::class),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('a Requires without exactly one key source was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_op_without_a_slot_is_unchanged_and_the_audit_passes(): void
    {
        $this->resource();
        $operation = $this->op();
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->assertNull($operation->declaredErrors());
        $this->postJson('/requires-rows/1/act', [])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('pass', $this->audit()->status->value);
    }

    public function test_a_subject_scoped_requirement_reports_the_subject_id(): void
    {
        $this->resource();
        $this->op(requires: [new Requires('rider', scope: 'subject')]);
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->postJson('/requires-rows/1/act', [])->assertStatus(428)
            ->assertJsonPath('requirements.0.scope', 'subject')
            ->assertJsonPath('requirements.0.subject', 1);
    }

    public function test_an_occurrence_scoped_requirement_without_a_seam_still_refuses_as_unregistered(): void
    {
        $this->resource();
        $this->op(requires: [new Requires('terms', scope: 'occurrence')], input: RequiresRowMemberInput::class);
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->postJson('/requires-rows/1/act', [])->assertStatus(428)
            ->assertJsonPath('requirements.0.key', 'terms')
            ->assertJsonPath('requirements.0.kind', 'unregistered')
            ->assertJsonPath('requirements.0.scope', 'occurrence');
    }

    public function test_an_occurrence_requirement_without_member_input_is_refused_at_registration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires [terms] at scope [occurrence], but its input declares no AgreementMemberData field');

        $this->op(requires: [new Requires('terms', scope: 'occurrence')]);
    }

    public function test_a_resource_occurrence_requirement_requires_a_write_member_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Particle resource [requires-rows] requires [terms] at scope [occurrence]');

        $this->resource(requires: [new Requires('terms', scope: 'occurrence', on: 'write')], input: false);
    }

    public function test_a_resource_cannot_attach_an_occurrence_requirement_to_reads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot declare an occurrence Requirement on reads');

        $this->resource(requires: [new Requires('terms', scope: 'occurrence', on: 'both')]);
    }

    public function test_an_installed_seam_resolver_answers_instead_and_the_audit_passes(): void
    {
        $this->resource();
        $this->op(requires: [new Requires('terms')]);
        RequiresRow::query()->create(['name' => 'a']);
        $this->app->instance(RequirementResolver::class, new NullRequirementResolver);
        $this->actingAs($this->actor());

        $this->postJson('/requires-rows/1/act', [])->assertOk();
        $this->assertSame('pass', $this->audit()->status->value);
    }

    // ── resources: REST ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_rest_a_write_requirement_refuses_store_update_destroy_and_leaves_reads_open(): void
    {
        $this->resource(requires: [new Requires('terms', on: 'write')]);
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->getJson('/requires-rows')->assertOk();
        $this->getJson('/requires-rows/1')->assertOk();
        $this->postJson('/requires-rows', ['name' => 'b'])->assertStatus(428)->assertJsonPath('requirements.0.kind', 'unregistered');
        $this->putJson('/requires-rows/1', ['name' => 'c'])->assertStatus(428);
        $this->deleteJson('/requires-rows/1')->assertStatus(428);

        $this->assertSame(['a'], RequiresRow::query()->pluck('name')->all(), 'nothing persisted or deleted on a refusal');
    }

    public function test_rest_a_create_requirement_refuses_only_store_and_leaves_update_delete_and_reads_open(): void
    {
        // agreements 16: `on: 'create'` binds the write that makes a NEW record; an existing one updates and deletes.
        $this->resource(requires: [new Requires('terms', on: 'create')]);
        RequiresRow::query()->create(['name' => 'a']);
        RequiresRow::query()->create(['name' => 'b']);
        $this->actingAs($this->actor());

        $this->getJson('/requires-rows')->assertOk();
        $this->getJson('/requires-rows/1')->assertOk();
        $this->postJson('/requires-rows', ['name' => 'new'])->assertStatus(428)->assertJsonPath('requirements.0.key', 'terms');
        $this->putJson('/requires-rows/1', ['name' => 'c'])->assertOk();
        $this->deleteJson('/requires-rows/2')->assertOk();

        $this->assertSame(['c'], RequiresRow::query()->pluck('name')->all(), 'the create refused; the update and delete ran');
    }

    public function test_a_create_requirement_is_declared_under_the_write_behavior_and_never_under_read(): void
    {
        $resource = $this->resource(requires: [new Requires('terms', on: 'create')], mount: false);
        $declarations = $this->app->make(SlotRequirementDeclarations::class);

        $this->assertSame(['terms'], array_map(fn (Requires $r): string => $r->agreement, $declarations->requirementsFor($resource->writeBehavior())));
        $this->assertSame([], $declarations->requirementsFor($resource->readBehavior()));

        $create = new Requires('terms', on: 'create');
        $this->assertSame([false, true, true], [$create->bindsRead(), $create->bindsWrite(), $create->bindsOnlyNewRecords()]);
        $this->assertTrue($create->appliesToSubject(null), 'a create (no subject yet) is bound');
        $this->assertFalse($create->appliesToSubject(new RequiresRow), 'an existing record is not');

        // on: write / read / both are unchanged: none binds only new records, each applies whatever the subject.
        foreach (['write' => [false, true], 'read' => [true, false], 'both' => [true, true]] as $on => [$read, $write]) {
            $requirement = new Requires('terms', on: $on);
            $this->assertSame([$read, $write, false, true, true], [
                $requirement->bindsRead(), $requirement->bindsWrite(), $requirement->bindsOnlyNewRecords(),
                $requirement->appliesToSubject(null), $requirement->appliesToSubject(new RequiresRow),
            ], "on: {$on}");
        }
    }

    public function test_an_operation_refuses_a_create_only_requirement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("on: 'create'");

        $this->op(requires: [new Requires('terms', on: 'create')], mount: false);
    }

    public function test_the_agreement_member_carries_a_title_so_no_host_renders_its_identifier(): void
    {
        // agreements 16, 14's finding: a host input carrying a member reached AgreementMemberData without a #[Title].
        $labels = $this->app->make(\Splicewire\Beam\Schema\IdentifierLabels::class)->findFrom([RequiresRowMemberInput::class]);

        $this->assertArrayNotHasKey(AgreementMemberData::class, $labels);
    }

    public function test_rest_a_read_requirement_refuses_index_and_show_and_leaves_writes_open(): void
    {
        $this->resource(requires: [new Requires('terms', on: 'read')]);
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->getJson('/requires-rows')->assertStatus(428)->assertJsonPath('requirements.0.key', 'terms');
        $this->getJson('/requires-rows/1')->assertStatus(428);
        $this->postJson('/requires-rows', ['name' => 'b'])->assertCreated();
        $this->putJson('/requires-rows/1', ['name' => 'c'])->assertOk();
        $this->deleteJson('/requires-rows/2')->assertOk();
    }

    public function test_rest_both_refuses_every_verb(): void
    {
        $this->resource(requires: [new Requires('terms')]);
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->getJson('/requires-rows')->assertStatus(428);
        $this->getJson('/requires-rows/1')->assertStatus(428);
        $this->postJson('/requires-rows', ['name' => 'b'])->assertStatus(428);
        $this->putJson('/requires-rows/1', ['name' => 'c'])->assertStatus(428);
        $this->deleteJson('/requires-rows/1')->assertStatus(428);
    }

    public function test_rest_a_caller_lacking_the_ability_gets_403_never_428(): void
    {
        $this->resource(requires: [new Requires('terms')]);
        RequiresRow::query()->create(['name' => 'a']);
        RequiresRowPolicy::$allow = false;
        $this->actingAs($this->actor());

        $this->getJson('/requires-rows')->assertForbidden();
        $this->getJson('/requires-rows/1')->assertForbidden();
        $this->postJson('/requires-rows', ['name' => 'b'])->assertForbidden();
        $this->putJson('/requires-rows/1', ['name' => 'c'])->assertForbidden();
        $this->deleteJson('/requires-rows/1')->assertForbidden();
    }

    public function test_rest_no_slot_leaves_every_verb_unchanged(): void
    {
        $this->resource();
        RequiresRow::query()->create(['name' => 'a']);
        $this->actingAs($this->actor());

        $this->getJson('/requires-rows')->assertOk();
        $this->getJson('/requires-rows/1')->assertOk();
        $this->postJson('/requires-rows', ['name' => 'b'])->assertCreated();
        $this->putJson('/requires-rows/1', ['name' => 'c'])->assertOk();
        $this->deleteJson('/requires-rows/1')->assertOk();
    }

    /**
     * Documented on ParticleResource::$requires and in the ADR amendment: a Public Read admits a guest past the read
     * ability, never past a Requirement — a guest has no party to have assented.
     */
    public function test_a_guest_admitted_by_public_read_is_still_refused_by_a_read_requirement(): void
    {
        $this->resource(requires: [new Requires('terms', on: 'read')], publicRead: RequiresRowData::class);
        RequiresRow::query()->create(['name' => 'a']);

        $this->getJson('/requires-rows')->assertStatus(428);
        $this->getJson('/requires-rows/1')->assertStatus(428);

        // The same guest reads once the Requirement binds only writes.
        $this->resource(requires: [new Requires('terms', on: 'write')], publicRead: RequiresRowData::class, mount: false);
        $this->getJson('/requires-rows')->assertOk();
    }

    // ── resources: frame ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_frame_write_requirement_refuses_store_update_destroy_with_the_428_body(): void
    {
        $resource = $this->resource(requires: [new Requires('terms', on: 'write')], mount: false);
        RequiresRow::query()->create(['name' => 'a']);
        $handler = $this->frame();
        $definition = $resource->toResourceDefinition();

        $this->assertCount(1, $handler->index($definition, [])['data']);
        $this->assertSame('a', $handler->show($definition, '1')['name']);
        $this->assertFrame428(fn () => $handler->store($definition, ['name' => 'b']));
        $this->assertFrame428(fn () => $handler->update($definition, '1', ['name' => 'c']));
        $this->assertFrame428(fn () => $handler->destroy($definition, '1'));
        $this->assertSame(['a'], RequiresRow::query()->pluck('name')->all());
    }

    public function test_frame_create_requirement_refuses_only_store(): void
    {
        $resource = $this->resource(requires: [new Requires('terms', on: 'create')], mount: false);
        RequiresRow::query()->create(['name' => 'a']);
        RequiresRow::query()->create(['name' => 'b']);
        $handler = $this->frame();
        $definition = $resource->toResourceDefinition();

        $this->assertFrame428(fn () => $handler->store($definition, ['name' => 'new']));
        $handler->update($definition, '1', ['name' => 'c']);
        $handler->destroy($definition, '2');
        $this->assertSame(['c'], RequiresRow::query()->pluck('name')->all());
    }

    public function test_frame_read_requirement_refuses_index_and_show_and_leaves_writes_open(): void
    {
        $resource = $this->resource(requires: [new Requires('terms', on: 'read')], mount: false);
        RequiresRow::query()->create(['name' => 'a']);
        $handler = $this->frame();
        $definition = $resource->toResourceDefinition();

        $this->assertFrame428(fn () => $handler->index($definition, []));
        $this->assertFrame428(fn () => $handler->rows($definition));
        $this->assertFrame428(fn () => $handler->indexWindow($definition, 10));
        $this->assertFrame428(fn () => $handler->show($definition, '1'));
        $this->assertSame('b', $handler->store($definition, ['name' => 'b'])['name']);
        $this->assertSame('c', $handler->update($definition, '1', ['name' => 'c'])['name']);
        $handler->destroy($definition, '2');
        $this->assertSame(['c'], RequiresRow::query()->pluck('name')->all());
    }

    public function test_frame_a_caller_the_write_policy_refuses_gets_403_never_428(): void
    {
        $resource = $this->resource(requires: [new Requires('terms')], mount: false, policy: 'requires-write');
        Gate::define('requires-write', fn (?Authenticatable $user): bool => false);
        RequiresRow::query()->create(['name' => 'a']);
        $handler = $this->frame();
        $definition = $resource->toResourceDefinition();

        foreach ([fn () => $handler->store($definition, ['name' => 'b']), fn () => $handler->update($definition, '1', ['name' => 'c'])] as $write) {
            try {
                $write();
                $this->fail('the write gate did not refuse');
            } catch (HttpResponseException $exception) {
                $this->fail('a caller the write gate refuses learned the Requirements (428)');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── readers ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** Coordinator ruling on (i): the key is OMITTED without a Requirement — never an empty list — and non-empty with one. */
    public function test_the_route_manifest_carries_requires_keys_only_where_a_behavior_has_requirements(): void
    {
        $this->resource(requires: [new Requires('terms', on: 'write'), new Requires('privacy', on: 'write'), new Requires('terms', on: 'write', scope: 'organisation')]);
        $this->op(requires: [new Requires('terms')]);
        $this->op(name: 'plain');

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        $this->assertSame(['terms'], $manifest['requires-rows.act']['requires']);
        foreach (['requires-rows.store', 'requires-rows.update', 'requires-rows.destroy'] as $name) {
            $this->assertSame(['terms', 'privacy'], $manifest[$name]['requires'], "{$name}: distinct keys, declaration order");
        }
        foreach (['requires-rows.index', 'requires-rows.show', 'requires-rows.plain'] as $name) {
            $this->assertArrayNotHasKey('requires', $manifest[$name], "{$name}: no Requirement, so no key at all");
        }
        foreach ($manifest as $name => $entry) {
            if (array_key_exists('requires', $entry)) {
                $this->assertNotSame([], $entry['requires'], "{$name}: a present `requires` is never empty");
            }
        }
    }

    public function test_the_route_manifest_is_byte_identical_with_no_requirement_anywhere(): void
    {
        $this->resource();
        $this->op();
        $source = $this->app->make(ParticleRouteManifestSource::class);
        $with = json_encode($source->toArray());

        $this->app->instance(RequirementDeclarations::class, new NullRequirementDeclarations);

        $this->assertSame(json_encode($source->toArray()), $with);
        $this->assertStringNotContainsString('"requires":', $with);
    }

    public function test_the_ability_map_adds_agreement_keys_for_party_and_organisation_scope_only(): void
    {
        $builder = $this->app->make(CanMapBuilder::class);
        $before = $builder->forPrincipal(null);
        $this->assertSame([], array_filter(array_keys($before), fn (string $key) => str_starts_with($key, 'agreement:')));

        $this->app->instance(RequirementDeclarations::class, new FakeRequiresDeclarations([
            'rows.act' => [new Requires('terms'), new Requires('rider', scope: 'subject')],
            'rows:write' => [new Requires('dpa', scope: 'organisation'), new Requires('terms')],
        ]));
        Gate::define('agreement:terms', fn (?Authenticatable $user = null): bool => true);

        $can = $builder->forPrincipal(null);

        $this->assertTrue($can['agreement:terms']);
        $this->assertFalse($can['agreement:dpa']);
        $this->assertArrayNotHasKey('agreement:rider', $can, 'a subject-scoped Requirement is per record');
        $this->assertSame($before, array_diff_key($can, ['agreement:terms' => 1, 'agreement:dpa' => 1]));
    }

    public function test_generated_types_note_the_refusal_body_for_a_resource_with_requirements_only(): void
    {
        $types = fn (): array => (new DeclaredParticleTypes(
            $this->app->make(ParticleResourceRegistry::class),
            new ParticleOperationRegistry,
            new ResourceContributionRegistry,
        ))->declared();

        $this->resource(mount: false);
        $this->assertArrayNotHasKey(RequirementRefusalData::class, $types());

        $this->resource(requires: [new Requires('terms', on: 'read')], mount: false);
        $this->assertSame(['resource [requires-rows] requires: [428]'], $types()[RequirementRefusalData::class]);
    }

    // ── contracts ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_neither_remedy_is_allowed_only_for_an_unregistered_requirement(): void
    {
        $unregistered = $this->outstanding('unregistered', null, null);
        $this->assertNull($unregistered->acceptOperation);
        $this->assertNull($unregistered->member);

        try {
            $this->outstanding('voice', null, null);
            $this->fail('a non-unregistered requirement without a remedy was accepted');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('An outstanding requirement must declare exactly one remedy: an accept operation or an input member.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->outstanding('unregistered', 'agreements.accept', 'agreement_member');
    }

    public function test_declares_equals_requirements_for_not_empty_in_every_beam_implementation(): void
    {
        $this->resource(requires: [new Requires('terms', on: 'read')], mount: false);
        $this->op(requires: [new Requires('terms')], mount: false);
        $this->op(name: 'plain', mount: false);

        $slot = $this->app->make(SlotRequirementDeclarations::class);
        $this->assertInstanceOf(SlotRequirementDeclarations::class, $this->app->make(RequirementDeclarations::class));
        $this->assertInstanceOf(SlotRequirementResolver::class, $this->app->make(RequirementResolver::class));
        $this->assertSame(['requires-rows.act', 'requires-rows:read'], array_keys($slot->all()));

        foreach ([$slot, new NullRequirementDeclarations] as $port) {
            foreach (['requires-rows.act', 'requires-rows.plain', 'requires-rows:read', 'requires-rows:write', 'nope'] as $behavior) {
                $this->assertSame($port->requirementsFor($behavior) !== [], $port->declares($behavior), $port::class." {$behavior}");
            }
        }
    }

    public function test_requires_validates_its_scope_on_and_slot_entries(): void
    {
        $this->assertSame(['terms', 'party', 'both'], [($r = new Requires('terms'))->agreement, $r->scope, $r->on]);
        $this->assertSame('occurrence', (new Requires('terms', scope: 'occurrence'))->scope);

        foreach ([fn () => new Requires('terms', scope: 'team'), fn () => new Requires('terms', on: 'list'), fn () => new Requires(' ')] as $bad) {
            try {
                $bad();
                $this->fail('an invalid Requires was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new ParticleOperation(resource: 'rows', name: 'act', kind: OperationKind::Write, handle: fn () => null, requires: ['terms']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param  list<Requires>|null  $requires */
    private function resource(
        ?array $requires = null,
        ?string $publicRead = null,
        bool $mount = true,
        ?string $policy = null,
        string|false|null $input = RequiresRowInput::class,
    ): ParticleResource {
        $resource = new ParticleResource(
            key: 'requires-rows',
            backing: RequiresRow::class,
            data: RequiresRowData::class,
            input: $input,
            project: fn (RequiresRow $row): RequiresRowData => new RequiresRowData($row->getKey(), $row->name),
            label: 'Requires rows',
            // Integrator ruling 2026-10-10: an explicit all-row population, so the read guard admits the list.
            scope: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNotNull('id'),
            policy: $policy,
            publicRead: $publicRead,
            requires: $requires,
        );
        $this->app->make(ParticleResourceRegistry::class)->register($resource);

        if ($mount) {
            Particle::mount('requires-rows', 'requires-rows')->only(['index', 'show', 'store', 'update', 'destroy']);
        }

        return $resource;
    }

    /** @param  list<Requires>|null  $requires */
    private function op(
        ?array $requires = null,
        string $name = 'act',
        bool $mount = true,
        string|false|null $input = false,
        ResolvesOperationSubject|string|null $subject = null,
    ): ParticleOperation
    {
        $operation = new ParticleOperation(
            resource: 'requires-rows',
            name: $name,
            kind: OperationKind::Write,
            handle: fn () => ['ok' => true],
            ability: false,
            input: $input,
            subject: $subject,
            output: RequiresRowData::class,
            requires: $requires,
        );
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        if ($mount) {
            Particle::ops('requires-rows', 'requires-rows', $name);
        }

        return $operation;
    }

    private function actor(): GenericUser
    {
        return new GenericUser(['id' => 1, 'name' => 'Ada']);
    }

    private function frame(): ParticleFrameResourceHandler
    {
        $actor = $this->actor();
        $this->actingAs($actor);

        return $this->app->make(ParticleFrameResourceHandler::class)->forActor($actor);
    }

    private function assertFrame428(callable $call): void
    {
        try {
            $call();
            $this->fail('the frame handler admitted a behavior with an outstanding Requirement');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $this->assertSame(428, $response->getStatusCode());
            $this->assertSame('unregistered', json_decode((string) $response->getContent(), true)['requirements'][0]['kind']);
        }
    }

    private function audit(): object
    {
        $findings = $this->app->make(UnseamedRequirementSlotAudit::class)->run();
        $this->assertCount(1, $findings);
        $this->assertSame(UnseamedRequirementSlotAudit::CHECK, $findings[0]->check);

        return $findings[0];
    }

    private function outstanding(string $kind, ?string $accept, ?string $member): OutstandingRequirementData
    {
        return new OutstandingRequirementData(key: 'terms', version: 1, digest: 'sha256:x', kind: $kind, title: 'Terms',
            scope: 'party', subject: null, readUrl: '/terms', acceptOperation: $accept, member: $member);
    }
}

class FakeRequiresDeclarations implements RequirementDeclarations
{
    /** @param  array<string, list<Requires>>  $declared */
    public function __construct(private array $declared) {}

    public function declares(string $behavior): bool
    {
        return $this->requirementsFor($behavior) !== [];
    }

    public function requirementsFor(string $behavior): array
    {
        return $this->declared[$behavior] ?? [];
    }

    public function all(): array
    {
        return $this->declared;
    }
}

class RequiresRow extends Model
{
    protected $table = 'requires_rows';

    protected $guarded = [];

    public $timestamps = false;
}

class RequiresRowData extends Data
{
    public function __construct(public int $id, public ?string $name = null) {}
}

class RequiresRowInput extends Data
{
    public function __construct(public ?string $name = null) {}
}

class RequiresRowMemberInput extends Data
{
    public function __construct(public ?AgreementMemberData $agreementMember = null) {}
}

class RequiresRowAgreementKeyResolver implements AgreementKeyResolver
{
    /** @var list<array{class-string, int|string}> */
    public static array $resolvedSubjects = [];

    public function resolve(Model $subject): string
    {
        self::$resolvedSubjects[] = [$subject::class, $subject->getKey()];

        return 'plans.'.$subject->getKey().'.terms';
    }
}

class RequiresRowPolicy
{
    public static bool $allow = true;

    public function viewAny(?Authenticatable $user): bool
    {
        return self::$allow;
    }

    public function view(?Authenticatable $user, Model $row): bool
    {
        return self::$allow;
    }

    public function create(?Authenticatable $user): bool
    {
        return self::$allow;
    }

    public function update(?Authenticatable $user, Model $row): bool
    {
        return self::$allow;
    }

    public function delete(?Authenticatable $user, Model $row): bool
    {
        return self::$allow;
    }
}
