<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorFailed;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 13 — the meta-audit: every registry-shaped singleton must describe
 * itself into the {@see ManifestIndex}, and this is the ONE gating check in the effort.
 *
 * Two things are proven here that a happy-path test would not prove:
 *
 *  1. **It can actually fire.** The audit excludes `tests/` and `fixtures/` paths by default, so a committed
 *     fixture provider under `tests/Surgeon/` would be skipped by the very rule these tests exist to
 *     exercise, and every "clean" assertion made from in here would be vacuous. Each disk test therefore
 *     writes its provider AND its registry class into a fresh temp root whose path contains none of the
 *     excluded fragments, and `require`s the registry so reflection (the shape test) and `getFileName()` (the
 *     ownership test) both see a real class inside the scanned root.
 *  2. **A gate finding actually stops a build.** {@see test_the_runner_throws_on_an_undescribed_registry()}
 *     drives the real {@see DoctorRunner} at its DEFAULT floor rather than asserting on the finding's status
 *     and calling it a build failure — the gate flag and the floor are two independent conditions and only
 *     the runner composes them.
 */
class UndescribedRegistryAuditTest extends TestCase
{
    private ?string $root = null;

    /** Class-name suffix keeping each test's planted classes distinct — a `require`d class cannot be undone. */
    private static int $plant = 0;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*.php') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    /**
     * Plant a provider that singleton-binds a registry class of the given shape into a fresh scan root, and
     * return an audit scoped to that root over the given index.
     *
     * @param  string  $properties  property declarations for the planted registry
     * @param  string  $methods  method declarations for the planted registry
     * @param  string  $binder  the container call the planted provider makes
     * @return array{0: UndescribedRegistryAudit, 1: string, 2: string} audit, registry FQCN, provider FQCN
     */
    private function plant(
        string $properties = 'private array $entries = [];',
        string $methods = 'public function register(string $k, string $v): void { $this->entries[$k] = $v; } public function get(string $k): ?string { return $this->entries[$k] ?? null; }',
        string $binder = '$this->app->singleton(PlantedRegistry%1$d::class);',
        ?ManifestIndex $index = null,
    ): array {
        $n = ++self::$plant;
        // "planted-scan" deliberately contains none of DEFAULT_EXCLUDED_PATHS' fragments.
        $this->root = sys_get_temp_dir().'/planted-scan-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $namespace = 'Splicewire\\Beam\\Tests\\Planted'.$n;

        $registry = "<?php\nnamespace {$namespace};\nclass PlantedRegistry{$n} {\n{$properties}\n{$methods}\n}\n";
        file_put_contents($registryFile = $this->root.'/PlantedRegistry.php', $registry);
        require $registryFile;

        $provider = sprintf(
            "<?php\nnamespace %s;\nuse Illuminate\\Support\\ServiceProvider;\nclass PlantedServiceProvider%d extends ServiceProvider {\npublic function register(): void {\n%s\n}\n}\n",
            $namespace,
            $n,
            sprintf($binder, $n),
        );
        file_put_contents($this->root.'/PlantedServiceProvider.php', $provider);

        return [
            new UndescribedRegistryAudit([$this->root], $index ?? new ManifestIndex, excludedPaths: []),
            $namespace.'\\PlantedRegistry'.$n,
            $namespace.'\\PlantedServiceProvider'.$n,
        ];
    }

    public function test_a_registry_shaped_singleton_that_does_not_describe_itself_produces_a_gate_tier_finding(): void
    {
        [$audit, $registry] = $this->plant();

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        // Fail, not warn: the runner throws at its default floor, so an advisory-severity finding on a gate
        // registration would only block a build that had already lowered its floor.
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertSame(UndescribedRegistryAudit::CHECK, $findings[0]->check);
        $this->assertSame([$registry], array_column($audit->undescribed(), 'registry'));
    }

    public function test_the_finding_names_both_the_registry_and_the_provider_that_should_describe_it(): void
    {
        [$audit] = $this->plant();

        $detail = $audit->run()[0]->detail;

        $this->assertStringContainsString('PlantedRegistry', $detail);
        // The provider is the load-bearing half: the index's direction means the fix has exactly one legal
        // home, and a finding that named only the registry would leave the reader to find it.
        $this->assertStringContainsString('PlantedServiceProvider', $detail);
        $this->assertStringContainsString('describe(new ManifestDescriptor', $detail);
    }

    public function test_describing_the_registry_silences_the_finding(): void
    {
        $index = new ManifestIndex;
        [$audit, $registry] = $this->plant(index: $index);

        $index->describe(new ManifestDescriptor(
            name: 'PlantedRegistry', of: 'planted', seam: ManifestSeam::SingletonAccumulator,
            arity: ManifestArity::PickOne, registerHint: 'register($k, $v)', where: $registry,
        ));

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame([], $audit->undescribed());
    }

    public function test_the_runner_throws_on_an_undescribed_registry_at_the_default_floor(): void
    {
        [$audit] = $this->plant();
        $this->app->instance(UndescribedRegistryAudit::class, $audit);

        try {
            (new DoctorRunner($this->app))->run([
                new DoctorRegistration('splicewire/laravel-beam', UndescribedRegistryAudit::class, gate: true),
            ]);
            $this->fail('a gate-tier undescribed registry must fail the build');
        } catch (DoctorFailed $e) {
            $this->assertNotEmpty($e->blocking);
            $this->assertSame(UndescribedRegistryAudit::CHECK, $e->blocking[0]->check);
        }
    }

    public function test_an_advisory_registration_of_the_same_findings_would_not_throw(): void
    {
        // The contrast that makes the gate flag mean something: identical Fail findings, no throw. This is
        // why registering it `gate: true` is a decision and not decoration.
        [$audit] = $this->plant();
        $this->app->instance(UndescribedRegistryAudit::class, $audit);

        $report = (new DoctorRunner($this->app))->run([
            new DoctorRegistration('splicewire/laravel-beam', UndescribedRegistryAudit::class, gate: false),
        ]);

        $this->assertNotEmpty($report->findings);
    }

    public function test_it_is_registered_as_the_only_gate_among_beams_surgeon_audits(): void
    {
        $gates = [];

        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $registration) {
            if ($registration->gate) {
                $gates[] = $registration->audit;
            }
        }

        $this->assertContains(UndescribedRegistryAudit::class, $gates);
    }

    public function test_a_bind_is_not_a_registry_because_it_cannot_accumulate(): void
    {
        [$audit] = $this->plant(binder: '$this->app->bind(PlantedRegistry%1$d::class);');

        $this->assertSame([], $audit->registries());
    }

    public function test_a_plain_service_singleton_is_not_registry_shaped(): void
    {
        // Array state but no entry/lookup pair: a client with options is a service, not a registry.
        [$audit] = $this->plant(
            properties: 'private array $options = [];',
            methods: 'public function send(string $body): string { return $body; }',
        );

        $this->assertSame([], $audit->registries());
    }

    public function test_a_write_only_sink_is_not_registry_shaped(): void
    {
        [$audit] = $this->plant(
            methods: 'public function register(string $k): void { $this->entries[] = $k; }',
        );

        $this->assertSame([], $audit->registries());
    }

    public function test_a_compose_many_pipeline_counts_even_though_its_read_engages_rather_than_looks_up(): void
    {
        // blockdoc's TransformPipeline in miniature: register() in, and the only read RUNS the chain. A
        // lookup-only verb list reads this as not-a-registry — which is one of the registries the ticket names.
        [$audit, $registry] = $this->plant(
            properties: 'private array $transforms = [];',
            methods: 'public function register(string $class): void { $this->transforms[] = $class; } public function transform(array $doc): array { return $doc; }',
        );

        $this->assertSame([$registry], array_column($audit->registries(), 'registry'));
    }

    public function test_a_config_seeded_registry_counts_without_any_write_verb(): void
    {
        // CompositionProfileRegistry in miniature: no register() at all, seeded from a config list, read by
        // key with a default. The array constructor parameter IS its entry path.
        [$audit, $registry] = $this->plant(
            properties: 'private array $resolved = [];',
            methods: 'public function __construct(array $classes = []) { $this->resolved = $classes; } public function get(string $n): ?string { return $this->resolved[$n] ?? null; }',
        );

        $this->assertSame([$registry], array_column($audit->registries(), 'registry'));
    }

    public function test_a_registry_the_estate_does_not_own_is_not_gated(): void
    {
        // The provider is inside the scanned root but the registry class is not: a third-party registry a
        // provider merely configures cannot describe itself, so it is an index nice-to-have, not an
        // obligation. ManifestIndex itself stands in for "a real registry-shaped class outside this root".
        [$audit] = $this->plant(binder: '$this->app->singleton(\\Splicewire\\Beam\\Manifest\\ManifestIndex::class);');

        $this->assertSame([], $audit->registries());
    }

    public function test_the_governed_scope_is_derived_from_the_indexs_own_membership(): void
    {
        // A package that has described nothing is not scanned at all; describing one registry opts the whole
        // package in. That ratchet is what makes a fleet-wide gate survivable.
        $this->assertSame([], UndescribedRegistryAudit::governedRoots(new ManifestIndex));

        $index = new ManifestIndex;
        $index->describe(new ManifestDescriptor(
            name: 'X', of: 'x', seam: ManifestSeam::SingletonAccumulator, arity: ManifestArity::RunAll,
            registerHint: 'x', where: 'x', package: 'vendor/does-not-exist',
        ));
        $this->assertSame([], UndescribedRegistryAudit::governedRoots($index), 'an absent package contributes no root');
    }

    public function test_beam_cores_own_registries_are_all_described(): void
    {
        // The dogfood assertion: beam-core is a member of its own index, so its `src/` is governed and every
        // registry-shaped singleton it binds must be listed. This is what caught RealmOverlayRegistry and
        // RealmResourceRegistry, which sat beside RealmRegistry undescribed.
        $audit = new UndescribedRegistryAudit(
            [dirname(__DIR__, 2).'/src'],
            $this->app->make(ManifestIndex::class),
        );

        $rows = $audit->registries();

        $this->assertNotEmpty($rows, 'the audit must actually see beam-core, or this assertion is vacuous');
        $this->assertSame([], array_column($audit->undescribed(), 'registry'));
    }

    /**
     * The pre-existing beam-core catalogue is FROZEN. Ticket 13 adds descriptors; it changes none, and this
     * pins name/seam/arity/order for all twelve so a later "tidy-up" of the index cannot quietly renumber or
     * relabel the entries other packages' order values were chosen relative to.
     */
    public function test_the_pre_existing_core_descriptors_are_unchanged(): void
    {
        $expected = [
            'ManifestIndex' => ['singleton-accumulator', 'run-all', 0],
            'BeamInstallManifest' => ['singleton-accumulator', 'run-all', 1],
            'BeamDoctorManifest' => ['singleton-accumulator', 'run-all', 2],
            'BeamSeedManifest' => ['singleton-accumulator', 'run-all', 3],
            'RouteManifestSource' => ['config-source', 'run-all', 10],
            'BeamSchemaRegistry' => ['chained-lookup', 'pick-one', 11],
            'ParticleResourceRegistry' => ['attribute-scan', 'pick-one', 12],
            'ParticleOperationRegistry' => ['attribute-scan', 'pick-one', 13],
            'RealmRegistry' => ['attribute-scan', 'pick-one', 14],
            'CapabilityRegistry' => ['singleton-accumulator', 'pick-one', 15],
            'ParticleWriter (write chain)' => ['pipeline-chain', 'compose-many', 20],
            'PayloadParticleReader (read chain)' => ['pipeline-chain', 'compose-many', 21],
        ];

        // Scoped to BEAM-CORE's own descriptors. The index is shared, and a booted dependency describing its
        // own registries is the ticket-13 membership ratchet working as designed — not a regression here. An
        // earlier whole-index equality assertion broke the moment `schemastud/laravel-data-schemas` described
        // `LensRegistry`/`DataOverlayRegistry` (ticket 11), which is exactly the outcome this effort wants.
        $actual = [];
        foreach ($this->app->make(ManifestIndex::class)->descriptors() as $descriptor) {
            if ($descriptor->package !== 'splicewire/laravel-beam') {
                continue;
            }

            $actual[$descriptor->name] = [$descriptor->seam->value, $descriptor->arity->value, $descriptor->order];
        }

        foreach ($expected as $name => $shape) {
            $this->assertArrayHasKey($name, $actual);
            $this->assertSame($shape, $actual[$name], "the pre-existing [{$name}] descriptor must not change");
        }

        // The sanctioned additions, and nothing else: beam-core describes exactly the frozen twelve
        // plus the two ticket-13 realm registries, the JN-15 SchemaSources tier registry, and the
        // AuditScanPaths audit scan-path contribution seam.
        $names = array_keys($actual);
        $wanted = [...array_keys($expected), 'RealmOverlayRegistry', 'RealmResourceRegistry', 'SchemaSources', 'AuditScanPaths'];
        sort($names);
        sort($wanted);
        $this->assertSame($wanted, $names, 'beam-core describes the frozen twelve plus exactly the sanctioned additions (ticket-13 realms + JN-15 SchemaSources + AuditScanPaths)');
    }
}
