<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\MarketingSampleAudit;
use Splicewire\Beam\Doctor\Support\MarketingCopySource;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see MarketingSampleAudit} — competitive-landscape ticket 06.
 *
 * The three claim kinds each have a red case here, and each red case is one of the four defects that
 * actually shipped on `/beam` (measured 2026-09-03, ticket 06's resolution table): a package name nothing
 * resolves (`splicewire/beam-starter`), a command name nothing registers
 * (`make:particle-resource`), and an attribute sample that a name-resolver passes and a constructor
 * rejects (`#[ParticleResource(label:…, group:…)]` — `key:` and `backing:` are required).
 *
 * The fourth case is the one this estate keeps paying for: an instrument whose answer cannot distinguish
 * "nothing there" from "didn't look". An unconfigured host is inconclusive; a document with no claims is
 * inconclusive and NAMES the document count; a `#[…]` span that is not a constant expression is COUNTED
 * and named rather than dropped.
 */
class MarketingSampleAuditTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // PID-keyed, per AGENTS.md: a fixed scratch name collides across concurrent sessions.
        $this->dir = sys_get_temp_dir().'/beam-marketing-test-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $contents);

        return $path;
    }

    private function manifest(array $names): string
    {
        return $this->write('installed.json', json_encode([
            'packages' => array_map(static fn (string $n): array => ['name' => $n], $names),
        ]));
    }

    private function audit(array $paths, array $providers = [], array $installed = ['splicewire/laravel-beam']): MarketingSampleAudit
    {
        return new MarketingSampleAudit(
            paths: $paths,
            providers: $providers,
            attributeNamespaces: MarketingSampleAudit::DEFAULT_ATTRIBUTE_NAMESPACES,
            installedJsonPath: $this->manifest($installed),
            commands: ['splicewire:beam:doctor', 'migrate'],
        );
    }

    public function test_an_unconfigured_host_is_inconclusive_rather_than_clean(): void
    {
        $findings = (new MarketingSampleAudit)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(MarketingSampleAudit::CHECK, $findings[0]->check);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('Beam ships no default population', $findings[0]->detail);
    }

    public function test_a_document_with_no_claims_names_the_document_count(): void
    {
        $this->write('copy.md', "Beam is a declarative CMS engine.\nNothing here is a claim.");

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('Read 1 marketing document', $findings[0]->detail);
    }

    public function test_a_package_name_the_estate_manifest_does_not_carry_is_a_warn(): void
    {
        $this->write('copy.md', 'composer create-project splicewire/beam-starter blog && composer setup');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertSame(MarketingSampleAudit::CHECK_PACKAGE, $findings[0]->check);
        $this->assertStringContainsString('splicewire/beam-starter', $findings[0]->detail);
    }

    public function test_a_package_name_the_manifest_carries_passes(): void
    {
        $this->write('copy.md', 'composer require splicewire/laravel-beam');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertStringContainsString('1 composer package name', $findings[0]->detail);
    }

    public function test_an_unregistered_artisan_command_is_a_warn(): void
    {
        $this->write('copy.md', 'php artisan make:particle-resource Article');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(MarketingSampleAudit::CHECK_COMMAND, $findings[0]->check);
        $this->assertStringContainsString('make:particle-resource', $findings[0]->detail);
    }

    public function test_a_registered_artisan_command_passes(): void
    {
        $this->write('copy.md', 'php artisan splicewire:beam:doctor');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_an_attribute_sample_missing_a_required_parameter_is_a_warn(): void
    {
        $this->write('copy.md', "#[ParticleResource(label: 'Articles', group: 'Content')]");

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(MarketingSampleAudit::CHECK_ATTRIBUTE, $findings[0]->check);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('does not survive instantiation', $findings[0]->detail);
    }

    public function test_the_corrected_attribute_sample_passes(): void
    {
        $this->write('copy.md', "#[ParticleResource(key: 'articles', backing: Article::class, label: 'Articles', group: 'Content')]");

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status, $findings[0]->detail);
        $this->assertStringContainsString('1 attribute sample', $findings[0]->detail);
    }

    public function test_an_attribute_the_estate_does_not_define_is_a_warn(): void
    {
        $this->write('copy.md', '#[BeamSchema]');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(MarketingSampleAudit::CHECK_ATTRIBUTE, $findings[0]->check);
        $this->assertStringContainsString('resolves to no attribute class', $findings[0]->detail);
    }

    public function test_a_php_sample_fragmented_across_jsx_string_literals_is_reconstructed(): void
    {
        // The shipped shape: `beam-content.tsx` splits the sample across `{tok('attr', '…')}` literals, so
        // a raw balanced-bracket scan matches JSX rather than the sample. The literal ORDER is the code
        // order, which is what makes the reconstruction exact.
        $this->write('beam-content.tsx', <<<'TSX'
            export function DeclareCodeCard() {
                return (
                    <CodeCard filename="app/Data/ArticleData.php">
                        {tok('attr', '#[ParticleResource(')}
                        {'\n  '}
                        {tok('attr', "label: 'Articles', group: 'Content',")}
                        {'\n'}
                        {tok('attr', ')]')}
                    </CodeCard>
                );
            }
            TSX);

        $findings = $this->audit([$this->dir.'/beam-content.tsx'])->run();

        $this->assertSame(MarketingSampleAudit::CHECK_ATTRIBUTE, $findings[0]->check);
        $this->assertStringContainsString('does not survive instantiation', $findings[0]->detail);
    }

    public function test_a_bare_attribute_mention_in_prose_is_not_instantiated(): void
    {
        // Measured at ~/Herd/splicewire: three doc entries say "declare a `#[ParticleResource]`" in a
        // sentence. A mention makes no claim about arguments, so instantiating it manufactures "Too few
        // arguments" against copy that is correct. The existence check still runs — see the next case.
        $this->write('copy.md', 'Declare a #[ParticleResource] and the routes mount themselves.');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status, $findings[0]->detail);
    }

    public function test_a_bare_mention_of_an_attribute_nobody_ships_is_still_a_warn(): void
    {
        $this->write('copy.md', 'Declare a #[BeamSchema] and you are done.');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(MarketingSampleAudit::CHECK_ATTRIBUTE, $findings[0]->check);
    }

    public function test_an_attribute_that_targets_a_parameter_is_probed_where_it_belongs(): void
    {
        // `#[Required, Max(120)]` annotates a promoted constructor parameter in the copy it came from.
        // Probed only as a class attribute it answers "cannot target class" — a fact about the probe.
        $this->write('copy.md', '#[Required, Max(120)]');

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status, $findings[0]->detail);
    }

    public function test_the_token_class_label_of_an_island_fragment_is_not_joined_into_the_sample(): void
    {
        // The islands wrap every fragment as `{tok('attr', '…')}`. Joining ALL literals interleaves the
        // token-class label with the code and yields a PHP syntax error against a CORRECT sample — which
        // is what the first live run at ~/Herd/splicewire reported.
        $this->write('beam-content.tsx', <<<'TSX'
            {tok('attr', '#[ParticleResource(')}
            {'\n  '}
            {tok('attr', "key: 'articles', backing: Article::class,")}
            {'\n'}
            {tok('attr', ')]')}
            TSX);

        $findings = $this->audit([$this->dir.'/beam-content.tsx'])->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status, $findings[0]->detail);
        $this->assertStringContainsString('1 attribute sample', $findings[0]->detail);
    }

    public function test_a_span_that_is_not_a_constant_expression_is_counted_not_dropped(): void
    {
        // `#[` inside markup, and a call in argument position — neither is a claim, and neither may be
        // silently discarded: the counter is what stops a clean-looking pass from meaning "did not look".
        $this->write('copy.md', "composer require splicewire/laravel-beam\n#[Foo(bar())]\n#[unterminated");

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('Did not look:', $findings[0]->detail);
        $this->assertStringContainsString('2 `#[…]` span', $findings[0]->detail);
    }

    public function test_a_blind_spot_is_reported_alongside_warn_rows(): void
    {
        $this->write('copy.md', "composer require splicewire/nope\n#[Foo(bar())]");

        $findings = $this->audit([$this->dir.'/copy.md'])->run();

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertFalse($findings[1]->conclusive);
        $this->assertStringContainsString('Did not look:', $findings[1]->detail);
    }

    public function test_a_provider_contributes_copy_that_is_not_a_file(): void
    {
        // The particle case: at splicewire/splicewire the prose bullets live in a ~49 KB `beam_particles`
        // payload, and the disk file beside it is an outbound projection. A scanner that reads only the
        // filesystem passes while the served page is wrong.
        $findings = $this->audit([], [new MarketingSampleAuditTestParticleSource])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(MarketingSampleAudit::CHECK_COMMAND, $findings[0]->check);
        $this->assertStringContainsString('beam page particle', $findings[0]->detail);
    }

    public function test_every_row_is_advisory_and_never_a_fail(): void
    {
        $this->write('copy.md', "composer require splicewire/nope\nphp artisan nope:nope\n#[BeamSchema]");

        foreach ($this->audit([$this->dir.'/copy.md'])->run() as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status);
        }
    }
}

class MarketingSampleAuditTestParticleSource implements MarketingCopySource
{
    public function documents(): array
    {
        return ['beam page particle 01a05516' => 'Scaffold one with php artisan make:particle-resource Article.'];
    }
}
