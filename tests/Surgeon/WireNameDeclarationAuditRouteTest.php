<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Doctor\DoctorStatus;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Splicewire\Beam\Data\Attributes\WireNameExemption;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\WireNameDeclarationAudit;
use Splicewire\Beam\Tests\TestCase;

class WireNameDeclarationAuditRouteTest extends TestCase
{
    #[DataProvider('wireSites')]
    public function test_it_reports_undeclared_wire_names_at_each_non_particle_site(
        string $path,
        string $method,
        string $dataClass,
        string $axis,
    ): void {
        Route::post($path, [WireNameDeclarationRouteController::class, $method]);

        $details = array_map(
            fn ($finding) => $finding->detail,
            array_filter($this->audit()->run(), fn ($finding) => $finding->status !== DoctorStatus::Pass),
        );

        $this->assertCount(1, $details);
        $this->assertStringContainsString(class_basename($dataClass).'::$calendar_id', $details[0]);
        $this->assertStringContainsString($axis, $details[0]);
    }

    #[DataProvider('declaredWireSites')]
    public function test_it_accepts_a_directionally_declared_wire_name_at_each_non_particle_site(
        string $path,
        string $method,
    ): void {
        Route::post($path, [WireNameDeclarationRouteController::class, $method]);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_documented_format_exemptions_apply_to_output_sites_but_not_request_inputs(): void
    {
        Route::post('api/v1/wire-exempt-request', [WireNameDeclarationRouteController::class, 'exemptRequest']);
        Route::post('api/v1/wire-exempt-response', [WireNameDeclarationRouteController::class, 'exemptResponse']);
        Route::post('api/v1/wire-exempt-stream', [WireNameDeclarationRouteController::class, 'exemptStream']);

        $findings = array_values(array_filter(
            $this->audit()->run(),
            fn ($finding) => $finding->status !== DoctorStatus::Pass,
        ));

        // WireNameExemption documents an external output vocabulary. It must not silently excuse a
        // request input, which still needs an explicit MapInputName/MapName declaration.
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('ExemptRequestWireData::$calendar_id', $findings[0]->detail);
        $this->assertStringContainsString('input', $findings[0]->detail);
    }

    /** @return iterable<string, array{string, string, class-string, string}> */
    public static function wireSites(): iterable
    {
        yield 'request input' => ['api/v1/wire-request', 'request', UnmappedRequestWireData::class, 'input'];
        yield 'response output' => ['api/v1/wire-response', 'response', UnmappedResponseWireData::class, 'output'];
        yield 'stream payload output' => ['api/v1/wire-stream', 'stream', UnmappedStreamWireData::class, 'output'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function declaredWireSites(): iterable
    {
        yield 'request input' => ['api/v1/wire-declared-request', 'declaredRequest'];
        yield 'response output' => ['api/v1/wire-declared-response', 'declaredResponse'];
        yield 'stream payload output' => ['api/v1/wire-declared-stream', 'declaredStream'];
    }

    private function audit(): WireNameDeclarationAudit
    {
        return WireNameDeclarationAudit::forRegistries(
            new ParticleResourceRegistry,
            new ParticleOperationRegistry,
            input: CamelCaseMapper::class,
            output: null,
            router: $this->app['router'],
        );
    }
}

class WireNameDeclarationRouteController
{
    #[RequestFromData(UnmappedRequestWireData::class)]
    public function request(): void {}

    #[ResponseFromData(UnmappedResponseWireData::class)]
    public function response(): void {}

    #[StreamsFromData('status', UnmappedStreamWireData::class)]
    public function stream(): void {}

    #[RequestFromData(DeclaredRequestWireData::class)]
    public function declaredRequest(): void {}

    #[ResponseFromData(DeclaredResponseWireData::class)]
    public function declaredResponse(): void {}

    #[StreamsFromData('status', DeclaredStreamWireData::class)]
    public function declaredStream(): void {}

    #[RequestFromData(ExemptRequestWireData::class)]
    public function exemptRequest(): void {}

    #[ResponseFromData(ExemptResponseWireData::class)]
    public function exemptResponse(): void {}

    #[StreamsFromData('status', ExemptStreamWireData::class)]
    public function exemptStream(): void {}
}

class UnmappedRequestWireData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

class UnmappedResponseWireData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

class UnmappedStreamWireData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

class DeclaredRequestWireData extends Data
{
    public function __construct(#[MapInputName('calendar_id')] public ?string $calendar_id = null) {}
}

class DeclaredResponseWireData extends Data
{
    public function __construct(#[MapOutputName('calendar_id')] public ?string $calendar_id = null) {}
}

class DeclaredStreamWireData extends Data
{
    public function __construct(#[MapOutputName('calendar_id')] public ?string $calendar_id = null) {}
}

#[WireNameExemption('Wire format', 'Spec §1', 'The external output format fixes this field name.')]
class ExemptRequestWireData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

#[WireNameExemption('Wire format', 'Spec §1', 'The external output format fixes this field name.')]
class ExemptResponseWireData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}

#[WireNameExemption('Wire format', 'Spec §1', 'The external output format fixes this field name.')]
class ExemptStreamWireData extends Data
{
    public function __construct(public ?string $calendar_id = null) {}
}
