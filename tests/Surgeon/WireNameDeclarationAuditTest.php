<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Splicewire\Beam\Data\Attributes\WireNameExemption;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\WireNameDeclarationAudit;

/**
 * The wire-name burn-down meter.
 *
 * The estate had an audit for "you did not declare your column MAP" and none for "you did not declare
 * your wire NAME" — the strictly more load-bearing of the two, because the column map only decides what
 * a write stores while the wire name decides what a client must SEND.
 *
 * Measured on `splicewire/laravel-beam-calendars`: 15 Data classes declared neither axis, so under a
 * host configuring `input => CamelCaseMapper, output => null` the package EMITTED `calendar_id` and
 * DEMANDED `calendarId` for the same field. Nothing reported it, in either direction.
 *
 * ⚠️ The negative assertions carry the weight here. A single-word property is not a finding — every
 * mapper is the identity on it, so there is nothing a declaration could disambiguate, and reporting it
 * would bury the real rows under noise.
 */
class WireNameDeclarationAuditTest extends TestCase
{
    public function test_a_create_result_only_dto_is_part_of_the_declared_wire_population(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'receipts', backing: 'App\\Models\\Receipt', createResultData: UndeclaredWireData::class,
        ));
        $findings = WireNameDeclarationAudit::forRegistries(
            $resources, new ParticleOperationRegistry, output: CamelCaseMapper::class,
        )->run();
        $details = implode(' ', array_map(fn ($finding) => $finding->detail, $findings));
        $this->assertStringContainsString('UndeclaredWireData', $details);
        $this->assertStringContainsString('calendar_id', $details);
    }

    public function test_an_output_only_dto_is_checked_only_against_the_output_contract(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'receipts', backing: 'App\\Models\\Receipt', createResultData: UndeclaredWireData::class,
        ));

        $findings = WireNameDeclarationAudit::forRegistries(
            $resources,
            new ParticleOperationRegistry,
            input: CamelCaseMapper::class,
            output: null,
        )->run();

        $details = array_values(array_map(fn ($finding) => $finding->detail, array_filter(
            $findings,
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        )));

        $this->assertCount(1, $details);
        $this->assertStringContainsString('bare snake output key', $details[0]);
        $this->assertStringNotContainsString('global input mapper', $details[0]);
    }

    public function test_a_bare_snake_output_property_is_reported_without_a_host_output_mapper(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'receipts', backing: 'App\\Models\\Receipt', data: SnakeOutputData::class,
        ));

        $findings = WireNameDeclarationAudit::forRegistries(
            $resources,
            new ParticleOperationRegistry,
            input: CamelCaseMapper::class,
            output: null,
        )->run();
        $details = implode(' ', array_map(fn ($finding) => $finding->detail, array_filter(
            $findings,
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        )));

        $this->assertStringContainsString('SnakeOutputData::$calendar_id', $details);
        $this->assertStringContainsString('calendarId', $details);
        $this->assertStringContainsString('output', $details);
    }

    public function test_a_real_input_mismatch_is_still_reported_through_the_registry_slots(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'receipts',
            backing: 'App\\Models\\Receipt',
            data: SingleWordData::class,
            input: UndeclaredWireData::class,
        ));

        $findings = WireNameDeclarationAudit::forRegistries(
            $resources,
            new ParticleOperationRegistry,
            input: CamelCaseMapper::class,
            output: null,
        )->run();
        $details = implode(' ', array_map(fn ($finding) => $finding->detail, array_filter(
            $findings,
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        )));

        $this->assertStringContainsString('UndeclaredWireData::$calendar_id', $details);
        $this->assertStringContainsString('global input mapper', $details);
    }

    /** Defaults to the flagship's real posture: camel on input, nothing on output. */
    private function detailsFor(string ...$classes): array
    {
        $findings = (new WireNameDeclarationAudit(
            $classes,
            input: CamelCaseMapper::class,
            output: null,
        ))->run();

        return array_map(fn ($f) => $f->detail, array_filter(
            $findings,
            fn ($f) => $f->status !== DoctorStatus::Pass,
        ));
    }

    public function test_it_reports_a_multi_word_property_with_no_declaration(): void
    {
        $details = $this->detailsFor(UndeclaredWireData::class);

        $this->assertCount(1, $details);
        $this->assertStringContainsString('calendar_id', $details[0]);
        $this->assertStringContainsString('UndeclaredWireData', $details[0]);
    }

    public function test_it_does_no_t_report_a_property_that_declares_its_wire_name(): void
    {
        $this->assertSame([], $this->detailsFor(DeclaredWireData::class));
    }

    public function test_a_class_level_mapper_does_not_declare_the_beam_ux_entry_wire_names(): void
    {
        // ced292a6's BeamUxEntryInputData shape: a class-level mapper loses to the host's global input
        // mapper, so neither snake property has a durable declaration of its published wire name.
        $details = $this->detailsFor(ClassMappedWireData::class);

        $this->assertCount(2, $details);
        $this->assertStringContainsString('parent_id', implode(' ', $details));
        $this->assertStringContainsString('nav_order', implode(' ', $details));
    }

    public function test_it_does_no_t_report_a_property_the_configured_mapper_leaves_alone(): void
    {
        // ⚠️ THE CORRECTION. Under `output => null` a camelCase read property publishes its own name,
        // deterministically — nothing is being decided for it, so there is nothing to declare. The
        // first version of this audit flagged all of them and suggested #[MapName('created_at')],
        // which would have CHANGED 212 published keys at the flagship. An audit that recommends a
        // breaking rename on a correct declaration is worse than no audit.
        $audit = new WireNameDeclarationAudit([CamelReadData::class], input: null, output: null);

        $findings = array_filter($audit->run(), fn ($f) => $f->status !== DoctorStatus::Pass);
        $this->assertSame([], array_map(fn ($f) => $f->detail, $findings));
    }

    public function test_it_doe_s_report_a_property_the_configured_mapper_transforms(): void
    {
        // The real defect: a snake property under a global camel input mapper publishes `calendarId`
        // while every other artifact says `calendar_id`. The mapper is choosing the contract.
        $audit = new WireNameDeclarationAudit(
            [SnakeInputData::class],
            input: CamelCaseMapper::class,
            output: null,
        );

        $details = array_map(fn ($f) => $f->detail, array_filter(
            $audit->run(), fn ($f) => $f->status !== DoctorStatus::Pass,
        ));

        $this->assertCount(1, $details);
        $this->assertStringContainsString('calendar_id', $details[0]);
        $this->assertStringContainsString('calendarId', $details[0]);
    }

    public function test_a_bare_snake_input_property_is_reported_without_a_host_input_mapper(): void
    {
        $details = array_map(fn ($finding) => $finding->detail, array_filter(
            (new WireNameDeclarationAudit(
                [SnakeInputData::class => ['input']],
                input: null,
                output: null,
            ))->run(),
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        ));

        $this->assertCount(1, $details);
        $this->assertStringContainsString('bare snake input key', $details[0]);
        $this->assertStringContainsString('calendarId', $details[0]);
    }

    public function test_it_does_no_t_report_single_word_properties(): void
    {
        // Every mapper is the identity on `$id` — there is nothing to disambiguate, so a finding here
        // would be noise that buries the real rows.
        $this->assertSame([], $this->detailsFor(SingleWordData::class));
    }

    public function test_it_passes_cleanly_when_every_class_declares(): void
    {
        $findings = (new WireNameDeclarationAudit([DeclaredWireData::class], input: CamelCaseMapper::class))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_it_is_advisor_y_and_never_throws_on_an_unloadable_class(): void
    {
        // The population is a host fact — which classes this host ships — so a class that cannot be
        // reflected must not take the whole audit down with it.
        $findings = (new WireNameDeclarationAudit(['Totally\\Missing\\Class'], input: CamelCaseMapper::class))->run();

        $this->assertNotEmpty($findings);
        $this->assertNotSame(DoctorStatus::Fail, $findings[0]->status);
    }

    public function test_an_explicit_exception_does_not_force_identity_mapped_siblings_to_declare(): void
    {
        // MarketListingInputData's real shape: repoFullName is the ONE exception, explicitly marked
        // because GitHub's public vocabulary is `repo_full_name`; installationNotes deliberately
        // follows the host's camel input contract. The explicit MapInputName is the marker. Inferring
        // that every sibling must now carry an identity annotation would turn a declaration into noise.
        $audit = new WireNameDeclarationAudit(
            [ExplicitExceptionalSiblingData::class],
            input: CamelCaseMapper::class,
            output: null,
        );

        $this->assertSame([], array_filter($audit->run(), fn ($f) => $f->status !== DoctorStatus::Pass));
    }

    public function test_a_documented_format_exemption_suppresses_only_the_bare_snake_output_finding(): void
    {
        $outputFindings = (new WireNameDeclarationAudit(
            [DocumentedSnakeFormatData::class => ['output']],
            input: null,
            output: null,
        ))->run();

        $this->assertCount(1, $outputFindings);
        $this->assertSame(DoctorStatus::Pass, $outputFindings[0]->status);
        $this->assertStringContainsString('1 documented wire-name exemption', $outputFindings[0]->detail);

        $inputDetails = array_map(fn ($finding) => $finding->detail, array_filter(
            (new WireNameDeclarationAudit(
                [DocumentedSnakeFormatData::class],
                input: CamelCaseMapper::class,
                output: null,
            ))->run(),
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        ));

        $this->assertCount(1, $inputDetails);
        $this->assertStringContainsString('global input mapper', $inputDetails[0]);
    }

    public function test_it_reports_bare_snake_output_even_when_the_output_mapper_preserves_that_name(): void
    {
        $details = array_map(fn ($finding) => $finding->detail, array_filter(
            (new WireNameDeclarationAudit(
                [SnakeOutputData::class],
                input: null,
                output: SnakeCaseMapper::class,
            ))->run(),
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        ));

        $this->assertCount(1, $details);
        $this->assertStringContainsString('calendar_id', $details[0]);
        $this->assertStringContainsString('calendarId', $details[0]);
    }

    #[DataProvider('incompleteWireNameExemptions')]
    public function test_a_wire_name_exemption_requires_a_format_citation_and_reason(
        string $format,
        string $citation,
        string $reason,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new WireNameExemption($format, $citation, $reason);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function incompleteWireNameExemptions(): iterable
    {
        yield 'format' => ['', 'RFC 8628 §3.4', 'The protocol owns this field.'];
        yield 'citation' => ['RFC 8628', ' ', 'The protocol owns this field.'];
        yield 'reason' => ['RFC 8628', '§3.4', ''];
    }

    public function test_an_invalid_declared_exemption_is_reported_instead_of_crashing_the_audit(): void
    {
        $findings = (new WireNameDeclarationAudit(
            [InvalidDocumentedSnakeFormatData::class],
            input: null,
            output: null,
        ))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('Wire-name exemption format must not be empty', $findings[0]->detail);
    }

    public function test_it_stays_quie_t_on_a_class_that_declares_nothing_at_all(): void
    {
        // A class that has made no declaration posture is not "partially" anything — silence here is
        // what keeps the audit's list short enough to read. The transformation rule still covers it.
        $audit = new WireNameDeclarationAudit([CamelReadData::class], input: null, output: null);

        $this->assertSame([], array_filter($audit->run(), fn ($f) => $f->status !== DoctorStatus::Pass));
    }
}

class ExplicitExceptionalSiblingData extends Data
{
    public function __construct(
        #[MapInputName('repo_full_name')] public ?string $repoFullName = null,
        public ?string $installationNotes = null,
    ) {}
}

class UndeclaredWireData extends Data
{
    // snake under a camel input mapper — the mapper rewrites it, so the key is not the author's.
    public function __construct(public ?string $calendar_id = null) {}
}

class DeclaredWireData extends Data
{
    public function __construct(#[MapName('calendar_id')] public ?string $calendar_id = null) {}
}

#[MapInputName(SnakeCaseMapper::class)]
class ClassMappedWireData extends Data
{
    public function __construct(
        public ?string $parent_id = null,
        public ?int $nav_order = null,
    ) {}
}

class CamelReadData extends Data
{
    public function __construct(public ?string $createdAt = null) {}
}

class SnakeInputData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

class SnakeOutputData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

class SingleWordData extends Data
{
    public function __construct(public ?string $id = null, public ?string $channel = null) {}
}

#[WireNameExemption(
    format: 'Authored frontmatter',
    citation: 'ADR-0212 frontmatter declaration seam',
    reason: 'The authoring grammar canonically spells this field in snake_case.',
)]
class DocumentedSnakeFormatData extends Data
{
    public function __construct(public ?int $nav_order = null) {}
}

#[WireNameExemption(
    format: '',
    citation: 'ADR-0212 frontmatter declaration seam',
    reason: 'The authoring grammar canonically spells this field in snake_case.',
)]
class InvalidDocumentedSnakeFormatData extends Data
{
    public function __construct(public ?int $nav_order = null) {}
}
