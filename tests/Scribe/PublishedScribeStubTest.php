<?php

namespace Splicewire\Beam\Tests\Scribe;

use Splicewire\Beam\Tests\TestCase;

/**
 * The `beam-scribe` stub is a **published artifact with no reader inside this package** — nothing here
 * requires it, so 861 green tests said nothing about whether it works. Ticket 07 found out the way that
 * class of gap is always found: `splicewire/www` published it, ran `scribe:generate`, and got
 * `Target class [Splicewire\Beam\Scribe\OpenApi\TagGroupGenerator] does not exist` — the class is
 * `TagHierarchyGenerator`, and had been all along.
 *
 * A wrong class name in a config stub is invisible from every angle inside the authoring package: it is a
 * string in a file that is copied, not loaded. It detonates in the host, after publication, at the moment
 * the host runs the generator the stub exists to configure — which on a fresh starter is the first boot
 * the OTB promise is being judged on.
 *
 * So this test **is** the reader. It evaluates the stub the same way Laravel's config loader would and
 * resolves every class it names, which turns "the stub is publishable" from an assumption into an
 * assertion. It is the same shape as beam-ux's codec round-trip guard and `DeadConfigKeyAudit`: the
 * durable fix for a write path nothing reads is to give it one.
 */
class PublishedScribeStubTest extends TestCase
{
    private const STUB = __DIR__.'/../../stubs/scribe/scribe.php';

    public function test_the_stub_evaluates(): void
    {
        $this->assertIsArray($this->stub(), 'The beam-scribe stub did not evaluate to a config array.');
    }

    /**
     * Every `::class` the stub names, resolved. Strategies, generators and enums all arrive here, because
     * the failure mode does not care which kind of reference it was.
     */
    public function test_every_class_the_stub_names_exists(): void
    {
        $missing = [];

        foreach ($this->classReferences($this->stub()) as $class) {
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'The beam-scribe stub names classes that do not exist: '.
            implode(', ', $missing).'. A host publishing this stub gets a BindingResolutionException '.
            'the first time it runs `scribe:generate`.');
    }

    /**
     * The three values ADR-0211 §7 calls load-bearing, asserted here rather than trusted: the emitter-only
     * pair the doctor audit also holds, and a non-empty derived exposure boundary.
     */
    public function test_the_stub_ships_the_emitter_only_defaults(): void
    {
        $stub = $this->stub();

        $this->assertSame('laravel', $stub['type']);
        $this->assertFalse($stub['laravel']['add_routes']);
        $this->assertNotEmpty($stub['routes'][0]['match']['prefixes']);
    }

    /**
     * The derivation, exercised: re-point the two keys the stub reads and the boundary follows them. This
     * is what a host does when it moves its sockets (as `www` did to `api/frame`), and the whole reason
     * the list is derived rather than literal.
     */
    public function test_the_exposure_boundary_follows_the_keys_that_position_the_sockets(): void
    {
        config(['frame.route_prefix' => 'api/frame', 'beam.ux.api_root' => 'api/beam/ux']);

        $prefixes = $this->stub()['routes'][0]['match']['prefixes'];

        $this->assertContains('api/*', $prefixes);
        $this->assertContains('api/frame/*', $prefixes);
        $this->assertContains('api/beam/ux/*', $prefixes);
        $this->assertNotContains('frame/*', $prefixes, 'A host that moved its frame socket should not '.
            'still be publishing the default prefix it moved away from.');
    }

    public function test_a_host_on_the_defaults_gets_beams_own_socket_prefixes(): void
    {
        config(['frame.route_prefix' => 'frame', 'beam.ux.api_root' => 'beam/ux']);

        $prefixes = $this->stub()['routes'][0]['match']['prefixes'];

        // The bare-install case ADR-0211 §7 was amended for: no `api/*` route exists, and the boundary
        // still has to describe something.
        $this->assertContains('frame/*', $prefixes);
        $this->assertContains('beam/ux/*', $prefixes);
    }

    /**
     * The degenerate case this test found, kept as a regression: a key present-and-NULL (published config,
     * value blanked) skips `config()`'s default argument, so a fallback applied at the LOOKUP leaves
     * `trim(null).'/*'` — the prefix `/*`, which matches every route on the site. The fallback belongs on
     * the trimmed value. An exposure boundary that fails open is worse than none.
     */
    public function test_a_blanked_key_never_widens_the_boundary_to_everything(): void
    {
        config(['frame.route_prefix' => null, 'beam.ux.api_root' => null]);

        $prefixes = $this->stub()['routes'][0]['match']['prefixes'];

        $this->assertNotContains('/*', $prefixes);
        $this->assertContains('frame/*', $prefixes);
        $this->assertContains('beam/ux/*', $prefixes);
    }

    /** @return array<string, mixed> */
    private function stub(): array
    {
        return require self::STUB;
    }

    /**
     * Class-strings anywhere in the evaluated stub. `::class` has already resolved to a string by the time
     * the file returns, so this walks the array rather than parsing the source — which also catches a
     * reference hiding in a nested strategy list, where the original one was.
     *
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private function classReferences(array $config): array
    {
        $found = [];

        array_walk_recursive($config, static function ($value) use (&$found): void {
            if (is_string($value) && str_contains($value, '\\')) {
                $found[] = $value;
            }
        });

        return array_values(array_unique($found));
    }
}
