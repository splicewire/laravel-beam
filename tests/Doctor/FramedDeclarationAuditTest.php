<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\Widget;
use Schemastud\Frame\Attributes\WidgetIn;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Schemastud\Frame\Registry\FormMode;
use Schemastud\Frame\Registry\InMemoryResourceRegistry;
use Schemastud\Frame\Registry\WidgetContextProjector;
use Schemastud\Frame\Vocabulary\WidgetVocabulary;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\FramedDeclarationAudit;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class FramedDeclarationAuditTest extends TestCase
{
    private string $directory;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/beam-framed-audit-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->directory);
        config(['frame.vocabulary' => $this->directory.'/vocabulary.json']);
        $this->app->instance(ParticleResourceRegistry::class, new ParticleResourceRegistry);
        $this->app->instance(CompositeResourceRegistry::class, (new CompositeResourceRegistry)->attach('beam', new ParticleResourceRegistryAdapter(app(ParticleResourceRegistry::class))));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function vocabulary(array $overrides = []): void
    {
        file_put_contents(config('frame.vocabulary'), json_encode(array_replace([
            'version' => WidgetVocabulary::Version,
            'contexts' => array_reverse(WidgetContextProjector::KnownContexts),
            'formModes' => array_reverse(array_column(FormMode::cases(), 'value')),
            'widgets' => ['badge' => [['contexts' => ['list-column']]], 'range' => [['stock' => ['number', 'integer']]], 'textarea' => [['stock' => ['string']]]],
        ], $overrides), JSON_THROW_ON_ERROR));
    }

    private function resource(string $key, string $data, string $label = ''): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: $key, backing: Model::class, data: $data, label: $label));
    }

    private function failures(string $check, ?string $key = null): array
    {
        return array_values(array_filter(app(FramedDeclarationAudit::class)->run(), fn (Finding $f) => $f->check === $check && $f->status === DoctorStatus::Fail && ($key === null || str_contains($f->detail, "Resource [{$key}]"))));
    }

    public function test_the_registered_advisory_audit_reports_bad_names_types_and_unlabelled_resources(): void
    {
        $this->vocabulary();
        $this->resource('bad', AuditBadData::class, 'Bad');
        $this->resource('hidden', AuditBadData::class);
        $this->resource('clean', AuditCleanData::class, 'Clean');
        $this->assertArrayNotHasKey('hidden', app(ParticleResourceRegistryAdapter::class)->all());
        $registrations = array_values(array_filter(app(BeamDoctorManifest::class)->registrations(), fn ($r) => $r->audit === FramedDeclarationAudit::class));
        $this->assertCount(1, $registrations);
        $this->assertFalse($registrations[0]->gate);
        $this->assertNotEmpty(app(DoctorRunner::class)->run($registrations, DoctorStatus::Warn)->findings);
        $this->assertCount(1, $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NAME, 'hidden'));
        $this->assertCount(1, $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NODE_TYPE, 'bad'));
        $this->assertSame([], $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NAME, 'clean'));
        $this->assertSame([], $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NODE_TYPE, 'clean'));
    }

    public function test_an_ordinary_edit_entry_is_reported_once_and_an_unknown_type_is_not_judged(): void
    {
        $this->vocabulary();
        $this->resource('duplicate', AuditDuplicateData::class);
        $edit = array_values(array_filter($this->failures(FramedDeclarationAudit::CHECK_WIDGET_NAME), fn (Finding $f) => str_contains($f->detail, 'in [edit]')));
        $this->assertCount(1, $edit);
        $this->assertStringContainsString('write-schema', $edit[0]->detail);
        $this->resource('unknown', AuditUnknownData::class);
        $walk = app(\Schemastud\Frame\Vocabulary\DeclaredWidgets::class)->walk(app(ParticleResourceRegistry::class)->definition('unknown'));
        $nodes = array_values(array_filter($walk->widgets, fn ($w) => $w->source === 'write-schema' && $w->name === 'range'));
        $this->assertCount(1, $nodes);
        $this->assertNull($nodes[0]->nodeType);
        $this->assertSame([], $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NODE_TYPE, 'unknown'));
    }

    public function test_a_missing_or_invalid_file_is_inconclusive_and_names_the_command(): void
    {
        $this->resource('bad', AuditBadData::class);
        foreach ([null, '{invalid json'] as $contents) {
            if ($contents !== null) {
                file_put_contents(config('frame.vocabulary'), $contents);
            }
            $findings = app(FramedDeclarationAudit::class)->run();
            $this->assertCount(4, $findings);
            $projection = array_values(array_filter($findings, fn (Finding $f) => $f->check === FramedDeclarationAudit::CHECK_PROJECTION));
            $this->assertCount(1, $projection);
            $this->assertTrue($projection[0]->conclusive);
            foreach ([FramedDeclarationAudit::CHECK_WIDGET_NAME, FramedDeclarationAudit::CHECK_WIDGET_NODE_TYPE] as $check) {
                $axis = array_values(array_filter($findings, fn (Finding $f) => $f->check === $check));
                $this->assertCount(1, $axis);
                $this->assertFalse($axis[0]->conclusive);
                $this->assertSame('No widget names were checked: see frame.vocabulary.', $axis[0]->detail);
            }
            $this->assertSame(FramedDeclarationAudit::CHECK_VOCABULARY, $findings[0]->check);
            $this->assertFalse($findings[0]->conclusive);
            $this->assertStringContainsString('pnpm frame:vocabulary --write', $findings[0]->detail);
        }
    }

    public function test_incompatible_headers_fail_and_conflicts_warn_without_a_node_type_finding(): void
    {
        foreach (['version' => 999, 'contexts' => [], 'formModes' => []] as $axis => $value) {
            $this->vocabulary([$axis => $value]);
            $this->assertCount(1, $this->failures(FramedDeclarationAudit::CHECK_VOCABULARY));
        }
        $this->vocabulary(['conflicts' => ['range' => ['edit']]]);
        $this->resource('conflict', AuditBadData::class);
        $warnings = array_values(array_filter(app(FramedDeclarationAudit::class)->run(), fn (Finding $f) => $f->check === FramedDeclarationAudit::CHECK_VOCABULARY && $f->status === DoctorStatus::Warn));
        $this->assertCount(1, $warnings);
        $this->assertSame([], $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NODE_TYPE));
    }

    public function test_projection_failures_do_not_stop_other_resources_and_other_producers_are_walked(): void
    {
        $this->vocabulary();
        $this->resource('broken', AuditProjectionData::class);
        $this->resource('clean', AuditCleanData::class);
        $this->assertNotEmpty($this->failures(FramedDeclarationAudit::CHECK_PROJECTION, 'broken'));
        $definition = app(ParticleResourceRegistry::class)->definition('clean')->withOverrides(key: 'other', data: AuditBadData::class);
        app(CompositeResourceRegistry::class)->attach('other', (new InMemoryResourceRegistry('other'))->register($definition));
        $this->assertCount(1, $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NAME, 'other'));
    }

    public function test_an_action_input_keeps_its_node_type_check_when_the_resource_uses_the_same_control(): void
    {
        $this->vocabulary();
        $this->resource('owner', AuditNumericData::class);
        $definition = app(ParticleResourceRegistry::class)->definition('owner')->withOverrides(key: 'with-action', actions: [
            new \Schemastud\Frame\Registry\ResourceActionDefinition(key: 'run', label: 'Run', scope: 'resource', method: 'POST', url: '/run', input: AuditBadData::class),
        ]);
        app(CompositeResourceRegistry::class)->attach('other', (new InMemoryResourceRegistry('other'))->register($definition));
        $failures = $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NODE_TYPE, 'with-action');
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('action-input', $failures[0]->detail);
        $this->assertStringContainsString('action run', $failures[0]->detail);
    }

    public function test_projection_failure_is_reported_even_when_vocabulary_is_unusable(): void
    {
        $this->resource('broken', AuditProjectionData::class);
        $this->assertNotEmpty($this->failures(FramedDeclarationAudit::CHECK_PROJECTION, 'broken'));
        $this->vocabulary(['version' => 999]);
        $this->assertNotEmpty($this->failures(FramedDeclarationAudit::CHECK_PROJECTION, 'broken'));
    }

    public function test_reserved_names_come_from_the_file_and_wire_keys_belongs_to_its_own_audit(): void
    {
        $this->vocabulary(['reserved' => ['range']]);
        $this->resource('reserved', AuditNumericData::class);
        $findings = $this->failures(FramedDeclarationAudit::CHECK_WIDGET_NAME, 'reserved');
        $this->assertNotEmpty($findings);
        $this->assertStringContainsString('reserved widget [range]', $findings[0]->detail);
        $this->assertFalse(defined(FramedDeclarationAudit::class.'::CHECK_WIRE_KEYS'));
    }

    public function test_a_bound_request_schema_resolver_is_named_without_calling_it(): void
    {
        $this->vocabulary();
        $this->resource('clean', AuditCleanData::class);
        $resolver = new AuditHostSchemaResolver;
        $this->app->instance(\Schemastud\Frame\Contracts\ResourceSchemaResolver::class, $resolver);
        foreach ([1, 2] as $walked) {
            if ($walked === 2) {
                $this->resource('broken', AuditProjectionData::class);
                $this->assertNotEmpty($this->failures(FramedDeclarationAudit::CHECK_PROJECTION, 'broken'));
            }
            $coverage = array_values(array_filter(app(FramedDeclarationAudit::class)->run(), fn (Finding $f) => str_starts_with($f->detail, 'Schema coverage unknown:')));
            $this->assertCount(1, $coverage);
            $this->assertFalse($coverage[0]->conclusive);
            $this->assertStringContainsString(AuditHostSchemaResolver::class, $coverage[0]->detail);
            $this->assertStringContainsString("which of the {$walked} walked resources", $coverage[0]->detail);
        }
    }

    public function test_collection_schemas_not_walked_are_counted_in_resources_even_when_projection_fails(): void
    {
        $this->vocabulary();
        $this->resource('ordinary', AuditCleanData::class);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'collection-records',
            backing: new \Splicewire\Beam\Collections\CollectionBacking(\Illuminate\Http\Request::create('/')),
            data: AuditCleanData::class,
            readOnly: true,
        ));
        foreach ([2, 3] as $walked) {
            if ($walked === 3) {
                $this->resource('broken', AuditProjectionData::class);
                $this->assertNotEmpty($this->failures(FramedDeclarationAudit::CHECK_PROJECTION, 'broken'));
            }
            $coverage = array_values(array_filter(app(FramedDeclarationAudit::class)->run(), fn (Finding $f) => str_starts_with($f->detail, 'Schemas not walked:')));
            $this->assertCount(1, $coverage);
            $this->assertSame(DoctorStatus::Warn, $coverage[0]->status);
            $this->assertSame("Schemas not walked: 1 of {$walked} resources are Collection-backed; CollectionFormSchema serves their schema per request, so the walk read their declared Data class instead: [collection-records].", $coverage[0]->detail);
        }
    }

    public function test_no_addressable_declarations_is_inconclusive(): void
    {
        $this->vocabulary();
        $findings = app(FramedDeclarationAudit::class)->run();
        $this->assertCount(4, $findings);
        foreach ($findings as $finding) {
            if ($finding->check !== FramedDeclarationAudit::CHECK_VOCABULARY) {
                $this->assertFalse($finding->conclusive);
            }
        }
    }
}

class AuditBadData extends Data
{
    public function __construct(#[Column('bagde')] public int $id, #[Widget('range')] public string $amount) {}
}
class AuditCleanData extends Data
{
    public function __construct(#[Column('badge')] public int $id, #[WidgetIn('edit', 'textarea')] public string $note) {}
}
class AuditDuplicateData extends Data
{
    public function __construct(#[WidgetIn('edit', 'missing')] public string $note) {}
}
class AuditUnknownData extends Data
{
    public function __construct(#[Widget('range')] public string|int $unknown) {}
}
#[WidgetIn('edit', 'textarea')]
class AuditProjectionData extends Data
{
    public function __construct(public string $note) {}
}

class AuditNumericData extends Data
{
    public function __construct(#[Widget('range')] public int $amount) {}
}

class AuditHostSchemaResolver implements \Schemastud\Frame\Contracts\ResourceSchemaResolver
{
    public function schema(\Schemastud\Frame\Registry\ResourceDefinition $resource, \Illuminate\Http\Request $request): ?array
    {
        throw new \LogicException('The declaration audit must not call a per-request resolver.');
    }
}
