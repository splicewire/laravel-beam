<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
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

    public function test_it_accepts_a_class_level_mapper_as_a_declaration(): void
    {
        // A class-level mapper is a deliberate declaration even though it names no key per property.
        $this->assertSame([], $this->detailsFor(ClassMappedWireData::class));
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

    public function test_it_catches_a_partiall_y_declared_class_even_when_the_mapper_is_the_identity(): void
    {
        // ⚠️ The gap the transformation-only rule left, and the realistic slip. After a sweep every
        // property is camelCase, so CamelCaseMapper is the IDENTITY on them — dropping one attribute
        // silently moves that field's published key from `calendar_id` to `calendarId` while the
        // transformation test stays quiet.
        //
        // A class that declares SOME of its multi-word wire names and not others has made a decision
        // and then failed to apply it. That is checkable at one moment, without a baseline.
        $audit = new WireNameDeclarationAudit(
            [PartiallyDeclaredData::class],
            input: CamelCaseMapper::class,
            output: null,
        );

        $details = array_map(fn ($f) => $f->detail, array_filter(
            $audit->run(), fn ($f) => $f->status !== DoctorStatus::Pass,
        ));

        $this->assertCount(1, $details);
        $this->assertStringContainsString('calendarId', $details[0]);
        $this->assertStringContainsString('siblings', $details[0]);
    }

    public function test_it_stays_quie_t_on_a_class_that_declares_nothing_at_all(): void
    {
        // A class that has made no declaration posture is not "partially" anything — silence here is
        // what keeps the audit's list short enough to read. The transformation rule still covers it.
        $audit = new WireNameDeclarationAudit([CamelReadData::class], input: null, output: null);

        $this->assertSame([], array_filter($audit->run(), fn ($f) => $f->status !== DoctorStatus::Pass));
    }
}

class PartiallyDeclaredData extends Data
{
    public function __construct(
        public ?string $calendarId = null,                              // attribute DROPPED
        #[MapName('series_ref')] public ?string $seriesRef = null,      // sibling still declares
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
    public function __construct(public ?string $calendar_id = null) {}
}

class CamelReadData extends Data
{
    public function __construct(public ?string $createdAt = null) {}
}

class SnakeInputData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

class SingleWordData extends Data
{
    public function __construct(public ?string $id = null, public ?string $channel = null) {}
}
