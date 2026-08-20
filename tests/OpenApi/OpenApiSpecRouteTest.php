<?php

namespace Splicewire\Beam\Tests\OpenApi;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Splicewire\Beam\OpenApi\OpenApiSpec;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\OpenApi\SpecFormat;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The core-mounted OpenAPI artifact routes (ADR-0211, ticket 21): two fixed URLs onto one artifact, the
 * JSON derived from the YAML, and the whole thing swappable at the {@see OpenApiSpecSource} seam.
 *
 * The acceptance the ticket cares about most is the LAST test here: rebinding the source changes what is
 * served with no route and no page change. That is the property the "one spec now" decision rests on.
 */
class OpenApiSpecRouteTest extends TestCase
{
    private string $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artifact = storage_path('app/scribe/openapi.yaml');
        File::ensureDirectoryExists(dirname($this->artifact));
        config(['beam.core.openapi.artifact' => $this->artifact]);
    }

    protected function tearDown(): void
    {
        File::delete($this->artifact);

        parent::tearDown();
    }

    public function test_both_urls_serve_the_same_spec_from_one_artifact(): void
    {
        $this->writeArtifact();

        $yaml = $this->get('beam/openapi.yaml');
        $json = $this->get('beam/openapi.json');

        $yaml->assertOk();
        $json->assertOk();

        $this->assertStringStartsWith('application/yaml', (string) $yaml->headers->get('Content-Type'));
        $this->assertStringStartsWith('application/json', (string) $json->headers->get('Content-Type'));

        // Same document, two representations — not two documents that happen to agree today.
        $this->assertSame(
            Yaml::parse($yaml->getContent()),
            json_decode($json->getContent(), true),
        );
    }

    public function test_the_json_derivation_carries_the_real_content(): void
    {
        $this->writeArtifact();

        $decoded = $this->get('beam/openapi.json')->json();

        $this->assertSame('3.0.3', $decoded['openapi']);
        $this->assertSame('Beam Test API', $decoded['info']['title']);
        $this->assertArrayHasKey('/api/things', $decoded['paths']);
    }

    public function test_both_urls_404_when_no_artifact_exists_and_nothing_is_written(): void
    {
        $this->assertFileDoesNotExist($this->artifact);

        $this->get('beam/openapi.yaml')->assertNotFound();
        $this->get('beam/openapi.json')->assertNotFound();

        // A public GET must never write to storage (ADR-0211 §4): no lazy generation, not even a stub.
        $this->assertFileDoesNotExist($this->artifact);
    }

    public function test_the_routes_are_named_so_a_docs_page_can_link_them(): void
    {
        $this->assertStringEndsWith('/beam/openapi.yaml', route(SpecFormat::Yaml->routeName()));
        $this->assertStringEndsWith('/beam/openapi.json', route(SpecFormat::Json->routeName()));
    }

    public function test_a_last_modified_header_tracks_the_artifact(): void
    {
        $this->writeArtifact();
        touch($this->artifact, 1_700_000_000);

        $this->get('beam/openapi.yaml')
            ->assertOk()
            ->assertHeader('Last-Modified', gmdate('D, d M Y H:i:s', 1_700_000_000).' GMT');
    }

    public function test_the_json_derivation_is_recomputed_when_the_artifact_changes(): void
    {
        $this->writeArtifact();
        touch($this->artifact, 1_700_000_000);
        $this->assertSame('Beam Test API', $this->get('beam/openapi.json')->json('info.title'));

        // Same path, new bytes, new mtime — the mtime-keyed cache entry must not answer for the old file.
        File::put($this->artifact, str_replace('Beam Test API', 'Regenerated API', $this->specYaml()));
        touch($this->artifact, 1_700_000_999);

        $this->assertSame('Regenerated API', $this->get('beam/openapi.json')->json('info.title'));
    }

    /**
     * The acceptance that matters: a host rebinds the seam and the SAME route serves something else,
     * with no route change and no page change. This is what "one spec now, variant-shaped seam" buys.
     */
    public function test_rebinding_the_spec_source_changes_what_is_served(): void
    {
        // No artifact on disk at all — proving the served bytes come from the binding, not the file.
        $this->assertFileDoesNotExist($this->artifact);

        $this->app->bind(OpenApiSpecSource::class, fn () => new class implements OpenApiSpecSource
        {
            public function spec(SpecFormat $format, Request $request): ?OpenApiSpec
            {
                $variant = $request->query('plan', 'free');

                return new OpenApiSpec("openapi: 3.0.3\ninfo:\n  title: {$variant} plan\n", $format);
            }
        });

        $this->get('beam/openapi.yaml')
            ->assertOk()
            ->assertSee('title: free plan');

        $this->get('beam/openapi.yaml?plan=pro')
            ->assertOk()
            ->assertSee('title: pro plan');
    }

    /**
     * The default follows the LOCAL DISK, because that is what Scribe writes through — a hardcoded
     * `storage/app/scribe` (what ADR-0211 §6 named) misses a Laravel 11+ skeleton entirely, where the
     * local disk is rooted at `storage/app/private`. Found on a real bare host, not in a unit test.
     */
    public function test_the_default_artifact_path_follows_the_local_disk_root(): void
    {
        config([
            'beam.core.openapi.artifact' => null,
            'filesystems.disks.local.root' => storage_path('app/private'),
        ]);

        $this->assertSame(
            storage_path('app/private').'/scribe/openapi.yaml',
            $this->app->make(ConfiguredArtifactSpecSource::class)->artifactPath(),
        );
    }

    public function test_the_default_artifact_path_falls_back_when_no_local_disk_is_configured(): void
    {
        config([
            'beam.core.openapi.artifact' => null,
            'filesystems.disks.local.root' => null,
        ]);

        $this->assertSame(
            storage_path('app').'/scribe/openapi.yaml',
            $this->app->make(ConfiguredArtifactSpecSource::class)->artifactPath(),
        );
    }

    private function writeArtifact(): void
    {
        File::put($this->artifact, $this->specYaml());
    }

    private function specYaml(): string
    {
        return <<<'YAML'
        openapi: 3.0.3
        info:
          title: Beam Test API
          version: 1.0.0
        paths:
          /api/things:
            get:
              summary: List things
              responses:
                '200':
                  description: OK
        YAML;
    }
}
