<?php

namespace Splicewire\Beam\Tests\Config;

use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\Generator\ConfigFileOutline;
use Splicewire\Beam\Config\Generator\ConfigSectionInferenceError;
use Splicewire\Beam\Config\Generator\ConfigSectionInferrer;
use Splicewire\Beam\Doctor\ConfigSectionCoverageAudit;
use Splicewire\Beam\Doctor\Support\ConfigRootCensus;
use Splicewire\Beam\Tests\Fixtures\Config\Ticket13MapNameSection;
use Splicewire\Beam\Tests\Fixtures\Config\Ticket13SnakeSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * The four parts of the config-sections ticket 13 CORE UNIT (ADR-0245, LANE-B-2330):
 *
 * - **Part 1** — an unmappable record key is a LEGIBLE error naming the root, the pointer and the keys, not nette's
 *   `Value '0' is not valid name`.
 * - **Part 2** (condition 2, ONE name source) — the schema and coverage resolve a key's wire name the SAME way the
 *   data-schemas generator does, through {@see \Splicewire\Beam\Config\ConfigWireNames}: a class-level Spatie mapper
 *   and a property `#[MapName]` both land the schema's `x-beam-config` on the wire name, and coverage of the same
 *   wire-keyed config agrees.
 * - **Part 4** (Option B) — a nullable or defaulted key is pruned from `required`, and an empty `#[MapValues]` map
 *   validates through the `anyOf` that admits `[]` beside the object.
 * - **Part 3** (report-only under clamp (a)) — the config-root census classifies each root's owner family and lists
 *   only the roots unowned by design; the audit's ownership finding never fails.
 */
class ConfigSectionTicket13Test extends TestCase
{
    // ---- Part 1: a legible inference error ------------------------------------------------------------------------

    public function test_part1_a_colliding_record_key_throws_a_legible_error_naming_root_pointer_and_keys(): void
    {
        // Two sibling keys reduce to the same property `fooBar`, so one cannot carry a distinct #[MapName]. The record
        // path reaches `mapName()`, which throws naming the root, the pointer and both keys.
        $file = sys_get_temp_dir().'/cs13-collide-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($file, "<?php return ['foo-bar' => 1, 'foo.bar' => 'two'];");

        try {
            $inferrer = new ConfigSectionInferrer(ConfigFileOutline::of($file));
            $this->expectException(ConfigSectionInferenceError::class);

            try {
                $inferrer->infer('cs13.collide', require $file);
            } catch (ConfigSectionInferenceError $e) {
                $this->assertSame('cs13.collide', $e->root);
                $this->assertContains($e->key, ['foo-bar', 'foo.bar'], 'the offending key is one of the colliding pair');
                $this->assertStringContainsString('cs13.collide', $e->getMessage());
                $this->assertStringContainsString('foo.bar', $e->getMessage());
                $this->assertStringContainsString('foo-bar', $e->getMessage(), 'it names the sibling that also takes the property');
                $this->assertStringContainsString('fooBar', $e->getMessage(), 'it names the property they collide on');
                $this->assertStringNotContainsString('is not valid name', $e->getMessage(), 'not nette\'s opaque message');

                throw $e;
            }
        } finally {
            @unlink($file);
        }
    }

    // ---- Part 2: one wire-name source, schema and coverage agree --------------------------------------------------

    public function test_part2_a_class_level_snake_mapper_keys_the_schema_and_coverage_on_the_same_wire_name(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(Ticket13SnakeSection::class);

        // The generator keyed the document on the wire (snake_case) names; the camelCase PHP names never appear.
        $this->assertArrayHasKey('review_required', $schema['properties']);
        $this->assertArrayHasKey('edit_ability', $schema['properties']);
        $this->assertArrayNotHasKey('reviewRequired', $schema['properties']);

        // x-beam-config lands on the wire name — the same resolution ConfigWireNames gives coverage.
        $this->assertArrayHasKey(ConfigSectionSchema::KEYWORD, $schema['properties']['review_required']);

        // Coverage of the snake-keyed config is fully covered; the camelCase spelling is UNCOVERED, proving coverage
        // resolves the wire name, not the PHP name.
        $this->assertSame([], ConfigSectionCoverage::uncovered(Ticket13SnakeSection::class, ['review_required' => true, 'edit_ability' => 'owner']));
        $this->assertSame(['/reviewRequired'], ConfigSectionCoverage::uncovered(Ticket13SnakeSection::class, ['reviewRequired' => true]));
    }

    public function test_part2_a_property_map_name_keys_the_schema_and_coverage_on_the_same_wire_name(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(Ticket13MapNameSection::class);

        $this->assertArrayHasKey('host-routes', $schema['properties']);
        $this->assertArrayNotHasKey('hostRoutes', $schema['properties']);
        $this->assertArrayHasKey(ConfigSectionSchema::KEYWORD, $schema['properties']['host-routes']);

        $this->assertSame([], ConfigSectionCoverage::uncovered(Ticket13MapNameSection::class, ['host-routes' => ['home' => '/'], 'enabled' => true]));
        $this->assertSame(['/hostRoutes'], ConfigSectionCoverage::uncovered(Ticket13MapNameSection::class, ['hostRoutes' => []]));
    }

    // ---- Part 4: required prune + empty-map anyOf -----------------------------------------------------------------

    public function test_part4_a_nullable_or_defaulted_key_is_pruned_from_required(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(Ticket13SnakeSection::class);
        $required = $schema['required'] ?? [];

        $this->assertContains('review_required', $required, 'a non-nullable key with no default stays required');
        $this->assertNotContains('edit_ability', $required, 'a nullable, defaulted key is pruned');
    }

    public function test_part4_an_empty_map_validates_through_the_any_of(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(Ticket13MapNameSection::class);
        $routes = $schema['properties']['host-routes'];

        $this->assertArrayHasKey('anyOf', $routes, 'the empty-map shape is admitted beside the object');
        $this->assertCount(2, $routes['anyOf']);
        $this->assertArrayHasKey(ConfigSectionSchema::KEYWORD, $routes, 'x-beam-config stays at the property level, beside anyOf, not inside a branch');

        $branches = $routes['anyOf'];
        $this->assertSame('object', $branches[0]['type']);
        $this->assertArrayHasKey('additionalProperties', $branches[0]);
        $this->assertSame('array', $branches[1]['type']);
        $this->assertSame(0, $branches[1]['maxItems']);

        $gate = app(AcceptanceGate::class);
        $this->assertTrue($gate->accepts(['host-routes' => [], 'enabled' => true], $schema), 'an empty map validates');
        $this->assertTrue($gate->accepts(['host-routes' => ['home' => '/'], 'enabled' => true], $schema), 'a populated map validates');
        $this->assertFalse($gate->accepts(['host-routes' => ['a-list-item'], 'enabled' => true], $schema), 'a non-empty list is neither object nor empty array');
        $this->assertFalse($gate->accepts(['host-routes' => null, 'enabled' => true], $schema), 'the non-nullable map still rejects null');
        $this->assertFalse($gate->accepts(['enabled' => true], $schema), 'the non-defaulted map remains required');
    }

    public function test_part4_a_nullable_defaulted_map_accepts_omitted_null_empty_and_populated_values(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(Ticket13NullableDefaultMapSection::class);
        $gate = app(AcceptanceGate::class);

        $this->assertNotContains('routes', $schema['required'] ?? []);
        $this->assertArrayHasKey(ConfigSectionSchema::KEYWORD, $schema['properties']['routes']);

        foreach ([
            'omitted' => [],
            'legacy null' => ['routes' => null],
            'empty map' => ['routes' => []],
            'populated map' => ['routes' => ['home' => '/']],
        ] as $case => $values) {
            $values = ['enabled' => true] + $values;
            $this->assertTrue($gate->accepts($values, $schema), $case.' must validate: '.json_encode($schema, JSON_THROW_ON_ERROR));
            $this->assertSame([], ConfigSectionCoverage::uncovered(Ticket13NullableDefaultMapSection::class, $values));
        }
    }

    public function test_part4_a_nullable_defaulted_map_still_refuses_lists_and_non_string_values(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(Ticket13NullableDefaultMapSection::class);
        $gate = app(AcceptanceGate::class);

        $this->assertFalse($gate->accepts(['enabled' => true, 'routes' => ['not-a-map']], $schema));
        $this->assertFalse($gate->accepts(['enabled' => true, 'routes' => ['home' => 42]], $schema));
    }

    // ---- Part 3: the census classifies and lists, report-only -----------------------------------------------------

    public function test_part3_the_census_classifies_families_from_requires_and_lists_only_unowned_by_design(): void
    {
        // Real shapes (LANE-B-2370). Family is decided from declared requires, NOT the vendor prefix or a name list:
        // - a package that requires beam is beam (the `requires` path, not an exact-name list);
        // - a splicewire-named package WITHOUT the beam require is beam-free (the sitemap/mdx/provision shape);
        // - a non-family vendor is third-party; the host and its own config/ tree are host.
        $base = sys_get_temp_dir().'/cs13-census-'.bin2hex(random_bytes(5));

        $fixtures = [
            // name, config files (relative => root), requires, isRoot
            ['splicewire/laravel-beam', ['beam/undeclared.php' => 'beam.undeclared'], [], false],
            ['splicewire/laravel-beam-widgets', ['widgets.php' => 'widgets'], ['splicewire/laravel-beam'], false],
            ['splicewire/laravel-beam-sitemap', ['sitemap.php' => 'sitemap'], ['illuminate/support'], false],
            ['spatie/laravel-medialibrary', ['media-library.php' => 'media-library'], ['illuminate/support'], false],
            ['laravel/framework', ['frameworkmail.php' => 'frameworkmail'], [], false],
            ['the/host-app', ['custom.php' => 'custom'], [], true],
        ];

        $installed = [];

        foreach ($fixtures as [$name, $files, $requires, $isRoot]) {
            $root = $base.'/'.str_replace('/', '_', $name);

            foreach (array_keys($files) as $relative) {
                $path = $root.'/config/'.$relative;
                @mkdir(dirname($path), 0777, true);
                file_put_contents($path, '<?php return [];');
            }

            $installed[] = ['name' => $name, 'path' => $root, 'requires' => $requires, 'isRoot' => $isRoot];
        }

        try {
            $registry = new ConfigSectionRegistry;
            $registry->section(Ticket13SnakeSection::class, owner: 'splicewire/laravel-beam');

            $census = new ConfigRootCensus($registry, $installed);
            $roots = $census->roots();

            // A declared root is owned from the registry; beam itself is on the ladder, so its family is beam.
            $this->assertSame('registry', $roots['cs13.snake']['source']);
            $this->assertTrue($roots['cs13.snake']['declared']);
            $this->assertSame('beam', $roots['cs13.snake']['family']);

            // Undeclared roots, owned from the config/ tree, classified by family from requires.
            $this->assertSame('config-tree', $roots['beam.undeclared']['source']);
            $this->assertFalse($roots['beam.undeclared']['declared']);
            $this->assertSame('beam', $roots['beam.undeclared']['family'], 'beam itself is on the ladder');
            $this->assertSame('beam', $roots['widgets']['family'], 'a package that REQUIRES beam is beam');
            $this->assertSame('beam-free', $roots['sitemap']['family'], 'splicewire-named, no beam require = beam-free');
            $this->assertSame('third-party', $roots['media-library']['family'], 'an unregistered non-family vendor is third-party');
            $this->assertSame('host', $roots['frameworkmail']['family'], 'the Laravel framework package is host, not third-party (coord ruling)');
            $this->assertSame('host', $roots['custom']['family'], 'the host root config/ tree is host');

            // Unowned by design = undeclared AND (beam-free | third-party). A beam or host root that is undeclared is a
            // GAP, not an excuse — it must not appear here.
            $unowned = $census->unownedByDesign();
            $this->assertArrayHasKey('sitemap', $unowned);
            $this->assertArrayHasKey('media-library', $unowned);
            $this->assertArrayNotHasKey('beam.undeclared', $unowned);
            $this->assertArrayNotHasKey('widgets', $unowned);
            $this->assertArrayNotHasKey('frameworkmail', $unowned, 'a host (framework) root is never unowned by design');
            $this->assertArrayNotHasKey('custom', $unowned);
            $this->assertArrayNotHasKey('cs13.snake', $unowned);
        } finally {
            foreach ($fixtures as [$name, $files]) {
                $root = $base.'/'.str_replace('/', '_', $name);

                foreach (array_keys($files) as $relative) {
                    @unlink($root.'/config/'.$relative);
                }

                @rmdir($root.'/config/beam');
                @rmdir($root.'/config');
                @rmdir($root);
            }

            @rmdir($base);
        }
    }

    public function test_part3_the_production_population_path_classifies_the_whole_install_without_a_caller_list(): void
    {
        // review-r1 HIGH: the census must run through its PRODUCTION population (Composer installed.json + host root),
        // not only a caller-supplied list. Constructed with no packages, it reads the whole install and classifies it.
        $registry = new ConfigSectionRegistry;
        $census = new ConfigRootCensus($registry);

        $roots = $census->roots();
        $this->assertNotEmpty($roots, 'the production read discovers installed + host config roots');

        // Every classification is one of the four families, and the unowned-by-design set obeys its predicate.
        foreach ($roots as $entry) {
            $this->assertContains($entry['family'], ['host', 'beam', 'beam-free', 'third-party'], "{$entry['root']} has a valid family");
        }

        // beam itself is installed in this suite and must classify as beam through the requires ladder, from the
        // production read alone.
        $beam = array_values(array_filter($roots, static fn (array $e): bool => $e['owner'] === 'splicewire/laravel-beam'));
        if ($beam !== []) {
            $this->assertSame('beam', $beam[0]['family'], 'the beam package classifies beam through the production read');
        }

        foreach ($census->unownedByDesign() as $entry) {
            $this->assertFalse($entry['declared']);
            $this->assertContains($entry['family'], ['beam-free', 'third-party']);
        }
    }

    public function test_part3_the_ownership_finding_is_report_only(): void
    {
        $registry = new ConfigSectionRegistry;
        $registry->section(Ticket13SnakeSection::class, owner: 'splicewire/laravel-beam');

        $findings = (new ConfigSectionCoverageAudit($registry))->run();

        // Exactly one ownership finding, and it is a pass. The audit introduces no fail (clamp (a): report-only).
        $ownership = array_values(array_filter($findings, static fn ($f): bool => str_contains($f->detail, 'ownership')));
        $this->assertCount(1, $ownership);
        $this->assertSame(DoctorStatus::Pass, $ownership[0]->status);
        $this->assertStringContainsString('report-only', $ownership[0]->detail);

        foreach ($findings as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status, 'the coverage audit stays report-only on core');
        }
    }

    public function test_part3_a_census_that_cannot_be_taken_reports_warn_not_pass(): void
    {
        // LANE-B-2370 MEDIUM: a census that throws during discovery or classification is INDETERMINATE, not a pass —
        // a status-based consumer or a future gate must not read it as a valid empty skip-set.
        $registry = new ConfigSectionRegistry;

        $audit = new class($registry) extends ConfigSectionCoverageAudit
        {
            protected function census(): ConfigRootCensus
            {
                return new class($this->sections) extends ConfigRootCensus
                {
                    public function roots(): array
                    {
                        throw new \RuntimeException('installed.json unreadable');
                    }
                };
            }
        };

        $findings = $audit->run();
        $ownership = array_values(array_filter($findings, static fn ($f): bool => str_contains($f->detail, 'ownership census not taken')));

        $this->assertCount(1, $ownership);
        $this->assertSame(DoctorStatus::Warn, $ownership[0]->status, 'a failed census is indeterminate, not a pass');
        $this->assertStringContainsString('installed.json unreadable', $ownership[0]->detail);
    }
}

#[ConfigRoot(root: 'cs13.nullable-map', title: 'Nullable map section')]
class Ticket13NullableDefaultMapSection extends ConfigSection
{
    /** @param  array<string, string>|null  $routes */
    public function __construct(
        #[ConfigKey]
        public bool $enabled,
        #[ConfigKey, MapValues('string')]
        public ?array $routes = [],
    ) {}
}
