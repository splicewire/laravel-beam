<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Surgeon\EnvelopeCopyAudit;

/**
 * {@see EnvelopeCopyAudit} — api-surface-coherence ticket 131.
 *
 * The load-bearing cases are three. A host class declaring its own `ResponseBody` is exactly one `Warn` row
 * and never a `Fail` (a host fact). An operation that RETURNS the envelope is not a row at all — beam-facade
 * 194 made `finish()` pass a returned `ResponseBody` through, so the lint must read declarations and never
 * call sites. And a file the parser cannot read is a COUNTER in the reading, not a warning and not silence.
 */
class EnvelopeCopyAuditTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function audit(): EnvelopeCopyAudit
    {
        return new EnvelopeCopyAudit(new FacadeConformanceScope([]));
    }

    /** @param  array<string, string>  $files  basename => source */
    private function scopeWith(array $files): FacadeConformanceScope
    {
        $dir = sys_get_temp_dir().'/beam-envelope-copy-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        foreach ($files as $name => $source) {
            file_put_contents($dir.'/'.$name, $source);
        }

        return new FacadeConformanceScope([$dir]);
    }

    // ── the copy — the shape eleven hosts carried ───────────────────────────────────────────────────────

    public function test_a_host_class_declaring_its_own_response_body_is_one_warn_row(): void
    {
        // Verbatim shape of a pre-130 host copy: static `created($data)` as a constructor, and a
        // `toResponse()` that builds the JsonResponse directly.
        $source = <<<'PHP'
        <?php

        namespace App\Data;

        use Illuminate\Http\JsonResponse;
        use Spatie\LaravelData\Data;

        class ResponseBody extends Data
        {
            public function __construct(public bool $success = true, public int $statusCode = 200, public mixed $data = null) {}

            public static function created(mixed $data): static
            {
                return new static(data: $data, statusCode: 201);
            }

            public static function invalid(array $errors): static
            {
                return new static(success: false, statusCode: 422);
            }

            public function toResponse($request): JsonResponse
            {
                return new JsonResponse($this->toArray(), $this->statusCode);
            }
        }
        PHP;

        $findings = (new EnvelopeCopyAudit($this->scopeWith(['ResponseBody.php' => $source])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertNotSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertSame(EnvelopeCopyAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('`App\Data\ResponseBody` declares its own envelope', $findings[0]->detail);
        $this->assertStringContainsString('`created()` is static and must be fluent', $findings[0]->detail);
        $this->assertStringContainsString('`invalid()` is static and must be fluent', $findings[0]->detail);
        $this->assertStringContainsString('builds the JsonResponse directly', $findings[0]->detail);
    }

    public function test_a_correct_copy_is_still_a_row_because_the_copy_is_the_finding(): void
    {
        $reading = $this->audit()->inspectSource(<<<'PHP'
        <?php

        namespace App\Data;

        class ResponseBody
        {
            public function created(): static { return $this; }

            public static function success(mixed $data = null): static { return new static; }
        }
        PHP);

        $this->assertNotNull($reading);
        $this->assertCount(1, $reading['rows']);
        $this->assertSame(EnvelopeCopyAudit::CHECK, $reading['rows'][0]['check']);
        $this->assertStringNotContainsString('Role rule', $reading['rows'][0]['detail']);
    }

    // ── what is NOT a finding ───────────────────────────────────────────────────────────────────────────

    public function test_a_payload_dto_merely_named_for_its_response_is_not_an_envelope(): void
    {
        // Measured 2026-09-02 at prahsys-gateway: `SaleResponseBody`, `CustomerAnalyticsResponseBody` and
        // nine more are payload DTOs, not envelopes. A name-only test fabricated eleven findings.
        $reading = $this->audit()->inspectSource(<<<'PHP'
        <?php

        namespace App\Data\Softpoint\Sale;

        use Spatie\LaravelData\Data;

        class SaleResponseBody extends Data
        {
            public function __construct(public string $transactionId, public int $amount) {}
        }
        PHP);

        $this->assertNotNull($reading);
        $this->assertSame([], $reading['rows']);
        $this->assertSame(0, $reading['inspected']);
    }

    public function test_a_named_variant_declaring_the_envelopes_vocabulary_is_a_copy(): void
    {
        $reading = $this->audit()->inspectSource(<<<'PHP'
        <?php

        namespace App\Data;

        class ApiResponseBody
        {
            public function created(): static { return $this; }
        }
        PHP);

        $this->assertNotNull($reading);
        $this->assertCount(1, $reading['rows']);
        $this->assertSame(EnvelopeCopyAudit::CHECK, $reading['rows'][0]['check']);
    }

    public function test_an_operation_returning_the_envelope_is_not_a_row(): void
    {
        // beam-facade 194: finish() passes a returned ResponseBody through, so this is the legal shape.
        $op = <<<'PHP'
        <?php

        namespace App\Ops;

        use Splicewire\Beam\Data\ResponseBody;

        class CircuitIntakeOp
        {
            public function handle(mixed $model): ResponseBody
            {
                return ResponseBody::success(['circuit' => $model])->created();
            }

            public function respond(mixed $payload): ResponseBody
            {
                return $payload instanceof ResponseBody ? $payload : ResponseBody::success($payload);
            }
        }
        PHP;

        $reading = $this->audit()->inspectSource($op);

        $this->assertNotNull($reading);
        $this->assertSame([], $reading['rows']);
        $this->assertSame(0, $reading['inspected'], 'a call site is not a declaration and is not inspected');

        // And with a clean subclass beside it, the whole reading is a CONCLUSIVE pass — the op is invisible.
        $findings = (new EnvelopeCopyAudit($this->scopeWith([
            'CircuitIntakeOp.php' => $op,
            'ListResponseBody.php' => $this->cleanSubclass(),
        ])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertStringContainsString('1 class inspected (1 envelope-shaped, 0 `toResponse()` renderers)', $findings[0]->detail);
    }

    public function test_a_subclass_keeping_the_roles_is_not_a_row(): void
    {
        $reading = $this->audit()->inspectSource($this->cleanSubclass());

        $this->assertNotNull($reading);
        $this->assertSame([], $reading['rows']);
        $this->assertSame(1, $reading['inspected']);
        $this->assertSame(1, $reading['envelopes']);
    }

    public function test_a_renderer_routed_through_the_safe_call_is_not_a_row(): void
    {
        $reading = $this->audit()->inspectSource(<<<'PHP'
        <?php

        namespace App\Http;

        use Illuminate\Contracts\Support\Responsable;
        use Illuminate\Http\JsonResponse;
        use Splicewire\Beam\Data\RendersJsonSafely;

        class Problem implements Responsable
        {
            use RendersJsonSafely;

            public function toResponse($request): JsonResponse
            {
                return $this->jsonResponseThatCannotThrow(fn () => ['title' => $this->title], 422);
            }
        }
        PHP);

        $this->assertNotNull($reading);
        $this->assertSame([], $reading['rows']);
        $this->assertSame(1, $reading['renderers']);
    }

    // ── the two rules on a class that is not a copy ─────────────────────────────────────────────────────

    public function test_a_subclass_redeclaring_a_fluent_member_static_breaks_the_role_rule(): void
    {
        $reading = $this->audit()->inspectSource(<<<'PHP'
        <?php

        namespace App\Data;

        use Splicewire\Beam\Data\ResponseBody;

        class ListResponseBody extends ResponseBody
        {
            public static function created(mixed $data): static { return new static(data: $data); }

            public function paginated($paginator): static { return $this; }
        }
        PHP);

        $this->assertNotNull($reading);
        $this->assertCount(1, $reading['rows']);
        $this->assertSame(EnvelopeCopyAudit::CHECK_ROLE, $reading['rows'][0]['check']);
        $this->assertStringContainsString('`created()` is static and must be fluent', $reading['rows'][0]['detail']);
        $this->assertStringContainsString('`paginated()` is fluent and must be static', $reading['rows'][0]['detail']);
    }

    public function test_a_responsable_building_json_directly_breaks_the_render_rule(): void
    {
        $reading = $this->audit()->inspectSource(<<<'PHP'
        <?php

        namespace App\Http;

        use Illuminate\Contracts\Support\Responsable;

        class Problem implements Responsable
        {
            public function toResponse($request)
            {
                return response()->json(['title' => $this->title], 422);
            }
        }
        PHP);

        $this->assertNotNull($reading);
        $this->assertCount(1, $reading['rows']);
        $this->assertSame(EnvelopeCopyAudit::CHECK_RENDER, $reading['rows'][0]['check']);
        $this->assertStringContainsString('`App\Http\Problem::toResponse()` builds its JsonResponse directly', $reading['rows'][0]['detail']);
    }

    // ── reach: the counter, the empty population, and beam's own definition ─────────────────────────────

    public function test_a_file_the_parser_cannot_read_is_counted_not_warned(): void
    {
        $scope = $this->scopeWith([
            'Broken.php' => "<?php\n\nclass ResponseBody extends {\n",
            'ListResponseBody.php' => $this->cleanSubclass(),
        ]);

        $findings = (new EnvelopeCopyAudit($scope))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 file could not be parsed and was NOT inspected', $findings[0]->detail);

        $census = (new EnvelopeCopyAudit($scope))->census();
        $this->assertSame(1, $census['unparsed']);
        $this->assertSame(1, $census['inspected']);
    }

    public function test_an_unreadable_file_alone_is_inconclusive_and_names_the_count(): void
    {
        $findings = (new EnvelopeCopyAudit($this->scopeWith([
            'Broken.php' => "<?php\n\nclass ResponseBody extends {\n",
        ])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('1 file could not be parsed', $findings[0]->detail);
    }

    public function test_an_empty_population_is_inconclusive_rather_than_a_pass(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertSame(EnvelopeCopyAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('non-beam host', $findings[0]->detail);
    }

    public function test_beams_own_definition_is_not_a_copy_of_itself(): void
    {
        $findings = (new EnvelopeCopyAudit(new FacadeConformanceScope([
            (string) realpath(__DIR__.'/../../src/Data'),
        ])))->run();

        foreach ($findings as $finding) {
            $this->assertNotSame(DoctorStatus::Warn, $finding->status, $finding->detail);
        }
    }

    private function cleanSubclass(): string
    {
        return <<<'PHP'
        <?php

        namespace App\Data;

        use Splicewire\Beam\Data\ResponseBody;

        class ListResponseBody extends ResponseBody
        {
            public function accepted(): static { $this->statusCode = 202; return $this; }

            public static function paginated($paginator): static { return parent::paginated($paginator); }
        }
        PHP;
    }
}
