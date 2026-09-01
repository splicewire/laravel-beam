<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorFailed;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 13 — the meta-audit: every registry-shaped singleton must declare
 * itself with `#[IsRegistry]`, and this is the ONE gating check in the effort.
 *
 * Registry-kernel ticket 21 moved the obligation from "push a ManifestDescriptor into beam's own index" to
 * "declare the attribute on the class" — see the audit's own docblock for why those are two acts.
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
     * @param  string  $contract  an extra interface declaration written into the SAME file as the registry
     *                            (so both derive the same owning package), `%1$d`-interpolated
     * @param  string  $implements  the planted registry's `implements` clause, `%1$d`-interpolated
     * @return array{0: UndescribedRegistryAudit, 1: string, 2: string} audit, registry FQCN, provider FQCN
     */
    private function plant(
        string $properties = 'private array $entries = [];',
        string $methods = 'public function register(string $k, string $v): void { $this->entries[$k] = $v; } public function get(string $k): ?string { return $this->entries[$k] ?? null; }',
        string $binder = '$this->app->singleton(PlantedRegistry%1$d::class);',
        ?RegistryIndex $index = null,
        string $declaration = '',
        string $contract = '',
        string $implements = '',
        bool $binderIsTrait = false,
    ): array {
        $n = ++self::$plant;
        // "planted-scan" deliberately contains none of DEFAULT_EXCLUDED_PATHS' fragments.
        $this->root = sys_get_temp_dir().'/planted-scan-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $namespace = 'Splicewire\\Beam\\Tests\\Planted'.$n;

        $registry = sprintf(
            "<?php\nnamespace %s;\n%s\n%sclass PlantedRegistry%d %s{\n%s\n%s\n}\n",
            $namespace,
            sprintf($contract, $n),
            $declaration,
            $n,
            sprintf($implements, $n),
            $properties,
            $methods,
        );
        file_put_contents($registryFile = $this->root.'/PlantedRegistry.php', $registry);
        require $registryFile;

        // A trait binder writes NO provider class at all — the point of the fixture is a file whose only
        // declaration is a trait, which is exactly beam-ux's `Concerns/Wires*` shape.
        $provider = $binderIsTrait
            ? sprintf(
                "<?php\nnamespace %s;\ntrait PlantedWires%d {\nprotected function register%d(): void {\n%s\n}\n}\n",
                $namespace,
                $n,
                $n,
                sprintf($binder, $n),
            )
            : sprintf(
                "<?php\nnamespace %s;\nuse Illuminate\\Support\\ServiceProvider;\nclass PlantedServiceProvider%d extends ServiceProvider {\npublic function register(): void {\n%s\n}\n}\n",
                $namespace,
                $n,
                sprintf($binder, $n),
            );
        file_put_contents($this->root.'/PlantedServiceProvider.php', $provider);

        return [
            new UndescribedRegistryAudit([$this->root], $index ?? new RegistryIndex, excludedPaths: []),
            $namespace.'\\PlantedRegistry'.$n,
            $namespace.'\\'.($binderIsTrait ? 'PlantedWires' : 'PlantedServiceProvider').$n,
        ];
    }

    /**
     * The `implements Registry` clause and the seven stubs it obliges — what makes a planted class the
     * GATE's population rather than the shape heuristic's (registry-kernel 76). Written out rather than
     * faked, because the discriminator is a real `implementsInterface()` read and a fixture that only
     * looked the part would test nothing.
     */
    private const CONTRACT_IMPLEMENTS = 'implements \\Rushing\\Popcorn\\Registries\\Registry ';

    private const CONTRACT_METHODS = <<<'PHP'
        public function register(\Rushing\Popcorn\Registries\RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static { $this->entries[(string) $key] = $entry; return $this; }
        public function has(\Rushing\Popcorn\Registries\RegistryKey|string $key): bool { return isset($this->entries[(string) $key]); }
        public function resolve(\Rushing\Popcorn\Registries\RegistryKey|string $key): mixed { return $this->entries[(string) $key]; }
        public function tryResolve(\Rushing\Popcorn\Registries\RegistryKey|string $key): mixed { return $this->entries[(string) $key] ?? null; }
        public function matches(\Rushing\Popcorn\Registries\RegistryKey|string $key): array { return []; }
        public function keys(): array { return array_keys($this->entries); }
        public function unfiltered(): \Rushing\Popcorn\Registries\Registry { return $this; }
        PHP;

    public function test_a_registry_shaped_singleton_that_does_not_describe_itself_reports_but_does_not_fail(): void
    {
        [$audit, $registry] = $this->plant();

        $findings = $audit->run();

        // Two findings, and the pairing is the ticket-76 split itself: the gate's own coverage line, plus
        // the shape heuristic's suspicion on the advisory channel. ⚠️ It is a Warn, NOT a Fail — "is this
        // registry-shaped class really a registry?" is a judgement about a class the audit does not own,
        // and a judgement that fails the build is a judgement someone else made for you.
        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertSame(UndescribedRegistryAudit::CHECK, $findings[1]->check);
        // Re-channelled, not dropped — the work-list is unchanged, only the severity moved.
        $this->assertSame([$registry], array_column($audit->undescribed(), 'registry'));
    }

    public function test_the_empty_gate_states_what_it_covered_and_what_it_did_not(): void
    {
        // This estate's signature defect is an instrument that reports success by not running, and the
        // gating population here is empty at every host measured — so the PASS has to be distinguishable
        // from an absent check by reading it.
        [$audit] = $this->plant();

        $pass = $audit->run()[0];

        $this->assertSame(DoctorStatus::Pass, $pass->status);
        $this->assertStringContainsString('Rushing\Popcorn\Registries\Registry', $pass->detail);
        $this->assertStringContainsString('NOT COVERED', $pass->detail);
        // The count of what it declined to judge, so a reader can tell "nothing there" from "did not look".
        $this->assertStringContainsString('1 class(es)', $pass->detail);
        $this->assertStringContainsString('UndeclaredRegistryShapeAudit', $pass->detail);
    }

    public function test_a_class_that_implements_the_registry_contract_without_the_attribute_fails(): void
    {
        // The gate's whole population: the class asserts registry-hood in its OWN source and omits the
        // attribute, so it contradicts itself in one file. That is grammar its author could have gotten
        // right without knowing which host would load it — this estate's stated bar for what may throw.
        [$audit, $registry] = $this->plant(
            implements: self::CONTRACT_IMPLEMENTS,
            methods: self::CONTRACT_METHODS,
        );

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('PlantedRegistry', $findings[0]->detail);
        $this->assertSame([$registry], array_column($audit->undescribed(), 'registry'));
    }

    public function test_the_finding_names_both_the_registry_and_the_provider_that_should_describe_it(): void
    {
        [$audit] = $this->plant();

        $detail = $audit->run()[1]->detail;

        $this->assertStringContainsString('PlantedRegistry', $detail);
        // The provider is the load-bearing half: the index's direction means the fix has exactly one legal
        // home, and a finding that named only the registry would leave the reader to find it.
        $this->assertStringContainsString('PlantedServiceProvider', $detail);
        $this->assertStringContainsString('#[IsRegistry(', $detail);
        // No loss of detail on the way to the advisory channel — the re-channelling only ADDS the sentence
        // saying why this row reports rather than blocks.
        $this->assertStringContainsString('ADVISORY', $detail);
    }

    public function test_declaring_the_registry_silences_the_finding(): void
    {
        // The remedy the finding prints, applied. Note it is applied to the CLASS, not pushed into an index
        // from a provider — which is why the audit no longer needs a populated index to answer.
        [$audit] = $this->plant(
            declaration: "#[\\Rushing\\Popcorn\\Registries\\IsRegistry(root: 'planted.entries', of: 'planted entries', arity: \\Rushing\\Popcorn\\Registries\\RegistryArity::PickOne)]\n",
        );

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame([], $audit->undescribed());
    }

    public function test_the_runner_throws_on_an_undescribed_registry_at_the_default_floor(): void
    {
        // Planted with the contract clause: since registry-kernel 76 that is the only shape that blocks,
        // and a shape-heuristic row here would prove the opposite of what this test is for.
        [$audit] = $this->plant(implements: self::CONTRACT_IMPLEMENTS, methods: self::CONTRACT_METHODS);
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
        [$audit] = $this->plant(implements: self::CONTRACT_IMPLEMENTS, methods: self::CONTRACT_METHODS);
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

    /**
     * Criterion 4 — the external-store registry. `FilesystemSchemaRegistry` in miniature: no plural state at
     * all, just a directory; `register()` in, `get()`/`has()` out; bound in a CLOSURE under its own package's
     * contract interface.
     *
     * Both halves of the fix are exercised at once, because either alone leaves the row invisible: without
     * the closure reader the shape is tested against the interface and falls out at the property check,
     * and without criterion 4 the concrete has no array to find.
     */
    public function test_an_external_store_registry_bound_under_its_own_contract_is_registry_shaped(): void
    {
        [$audit, $registry] = $this->plant(
            properties: '',
            methods: 'public function __construct(protected string $directory) {} '
                .'public function register(array $schema): void {} '
                .'public function get(string $id): ?array { return null; } '
                .'public function has(string $id): bool { return false; }',
            binder: '$this->app->singleton(PlantedContract%1$d::class, function ($app) { '
                .'return new PlantedRegistry%1$d("/tmp"); });',
            contract: 'interface PlantedContract%1$d { public function register(array $schema): void; '
                .'public function get(string $id): ?array; }',
            implements: 'implements PlantedContract%1$d ',
        );

        $rows = $audit->registries();

        // The row is keyed by the ABSTRACT — the container key a host codes against — with the concrete the
        // closure builds recorded beside it.
        $this->assertSame([str_replace('PlantedRegistry', 'PlantedContract', $registry)], array_column($rows, 'registry'));
        $this->assertSame([$registry], array_column($rows, 'concrete'));
    }

    public function test_a_value_object_with_a_setter_a_getter_and_a_path_is_not_registry_shaped(): void
    {
        // The false positive criterion 4 is tuned against, and the reason condition (b) exists: this has a
        // write verb, a read verb and a `string $path` constructor, and it is a value object. It is bound
        // under its own concrete class name, so no contract KEY was ever declared for it.
        [$audit] = $this->plant(
            properties: 'private string $value = "";',
            methods: 'public function __construct(protected string $path = "/tmp") {} '
                .'public function setValue(string $v): void { $this->value = $v; } '
                .'public function getValue(): string { return $this->value; }',
        );

        $this->assertSame([], $audit->registries());
    }

    public function test_a_contract_bound_class_with_no_external_store_is_not_registry_shaped(): void
    {
        // Condition (c) on its own: a contract key and a write/read pair, but nothing saying WHERE the
        // entries live. A class with neither plural state nor a store handle keeps its entries nowhere.
        [$audit] = $this->plant(
            properties: 'private string $only = "";',
            methods: 'public function set(string $v): void { $this->only = $v; } '
                .'public function get(): string { return $this->only; }',
            binder: '$this->app->singleton(PlantedContract%1$d::class, fn () => new PlantedRegistry%1$d);',
            contract: 'interface PlantedContract%1$d { public function get(): string; }',
            implements: 'implements PlantedContract%1$d ',
        );

        $this->assertSame([], $audit->registries());
    }

    public function test_a_closure_that_names_no_concrete_still_reports_nothing(): void
    {
        // The shallowness of the closure reader, pinned. A closure resolving out of the container names no
        // class, so the shape is tested against the interface and no claim is made — the pre-existing
        // behaviour, kept, because guessing here would gate a class the provider never built.
        [$audit] = $this->plant(
            properties: '',
            methods: 'public function __construct(protected string $directory) {} '
                .'public function register(array $schema): void {} '
                .'public function get(string $id): ?array { return null; }',
            binder: '$this->app->singleton(PlantedContract%1$d::class, fn ($app) => $app->make("planted"));',
            contract: 'interface PlantedContract%1$d { public function get(string $id): ?array; }',
            implements: 'implements PlantedContract%1$d ',
        );

        $this->assertSame([], $audit->registries());
    }

    /**
     * Registry-kernel ticket 54. A provider that splits `register()` across `Concerns\Wires*` TRAITS has no
     * binding call inside any class node, and beam-ux does this for its whole registration surface — so
     * three ledger rows were structurally unreachable and the audit reported a clean package.
     */
    public function test_a_binding_written_in_a_provider_trait_is_found(): void
    {
        [$audit, $registry, $trait] = $this->plant(binderIsTrait: true);

        $rows = $audit->registries();

        $this->assertSame([$registry], array_column($rows, 'registry'));
        // The trait is what the finding names, because the trait is where the reader FINDS the binding.
        $this->assertSame([$trait], array_column($rows, 'provider'));
    }

    public function test_a_trait_that_binds_nothing_is_not_read_as_a_provider(): void
    {
        // The signal is `$this->app-><binding method>()` in the trait's own body, not the file being a
        // trait: a trait calling a same-named method on anything else contributes no rows.
        [$audit] = $this->plant(
            binder: '$this->registrar->singleton(PlantedRegistry%1$d::class);',
            binderIsTrait: true,
        );

        $this->assertSame([], $audit->registries());
    }

    /**
     * Registry-kernel ticket 54. `$registry = new X; $registry->register(...); return $registry;` — the
     * seed-then-return spelling, live in `rushing/laravel-timeline-schema`'s provider.
     */
    public function test_a_closure_that_seeds_a_variable_and_returns_it_names_its_concrete(): void
    {
        [$audit, $registry] = $this->plant(
            properties: '',
            methods: 'public function __construct(protected string $directory = "/tmp") {} '
                .'public function register(array $schema): void {} '
                .'public function get(string $id): ?array { return null; }',
            binder: '$this->app->singleton(PlantedContract%1$d::class, function ($app) { '
                .'$registry = new PlantedRegistry%1$d("/tmp"); $registry->register([]); return $registry; });',
            contract: 'interface PlantedContract%1$d { public function register(array $schema): void; '
                .'public function get(string $id): ?array; }',
            implements: 'implements PlantedContract%1$d ',
        );

        $this->assertSame([$registry], array_column($audit->registries(), 'concrete'));
    }

    public function test_a_closure_that_assigns_the_returned_variable_twice_names_no_concrete(): void
    {
        // Two assignments is a BRANCH, and a branch has no single concrete to name. Refusing beats picking
        // the first one seen: a wrong concrete produces a gate finding against a class nobody built.
        [$audit] = $this->plant(
            properties: '',
            methods: 'public function __construct(protected string $directory = "/tmp") {} '
                .'public function register(array $schema): void {} '
                .'public function get(string $id): ?array { return null; }',
            binder: '$this->app->singleton(PlantedContract%1$d::class, function ($app) { '
                .'$registry = new PlantedRegistry%1$d("/tmp"); '
                .'if ($app) { $registry = $app->make("other"); } return $registry; });',
            contract: 'interface PlantedContract%1$d { public function register(array $schema): void; '
                .'public function get(string $id): ?array; }',
            implements: 'implements PlantedContract%1$d ',
        );

        $this->assertSame([], $audit->registries());
    }

    public function test_a_registry_the_estate_does_not_own_is_not_gated(): void
    {
        // The provider is inside the scanned root but the registry class is not: a third-party registry a
        // provider merely configures cannot describe itself, so it is an index nice-to-have, not an
        // obligation. RegistryIndex itself stands in for "a real registry-shaped class outside this root".
        [$audit] = $this->plant(binder: '$this->app->singleton(\\Rushing\\Popcorn\\Registries\\RegistryIndex::class);');

        $this->assertSame([], $audit->registries());
    }

    public function test_the_governed_scope_is_derived_from_the_indexs_own_membership(): void
    {
        // A package that has described nothing is not scanned at all; describing one registry opts the whole
        // package in. That ratchet is what makes a fleet-wide gate survivable.
        //
        // A freshly-constructed index is NOT empty — it describes itself at the zero-segment root (ticket
        // 20 D4) — so this also pins that self-hosting governs nothing. Self-hosting is structural; only a
        // package describing a registry of its own is an opt-in.
        $this->assertSame([], UndescribedRegistryAudit::governedRoots(new RegistryIndex));
    }

    /**
     * The dogfood assertion: every registry-shaped singleton beam-core binds declares `#[IsRegistry]`.
     *
     * This is what caught `RealmOverlayRegistry` and `RealmResourceRegistry` sitting undescribed beside
     * `RealmRegistry`, and `FacadeConformanceScope` the moment beam-facade bound it.
     *
     * **The residue is currently zero, and that is a measurement rather than an aspiration.** Registry-kernel
     * ticket 21 declared fourteen classes; the five descriptors it did NOT convert — `ManifestIndex` (moved
     * to the kernel), the two particle pipelines (`bind()`, so not singletons), `BeamSchemaRegistry` (a
     * resolver, ticket 01) and `RouteManifestSource` (an interface over a config array, ticket 25) — are all
     * invisible to the structural test for reasons that are each a closed decision. If a legitimate exception
     * ever does appear, it belongs in `registry.undeclared-shape`'s `const` with its argument inline, not in
     * a widened assertion here (ticket 14 D10, ticket 35 §2).
     */
    public function test_beam_cores_own_registries_all_declare_themselves(): void
    {
        $audit = new UndescribedRegistryAudit(
            [dirname(__DIR__, 2).'/src'],
            $this->app->make(RegistryIndex::class),
        );

        $rows = $audit->registries();

        $this->assertNotEmpty($rows, 'the audit must actually see beam-core, or this assertion is vacuous');
        $this->assertSame([], array_column($audit->undescribed(), 'registry'));
    }

    /**
     * Registry-kernel ticket 57 — a **position-3 ladder** is ejected before any criterion runs.
     *
     * The planted class is the exact silhouette the structural test is built to catch: singleton-bound,
     * plural array state, a `register*()` entry path and a `get()` lookup path. All three criteria pass.
     * The one thing that differs is its TYPE — it declares `Laddered` and neither `Registry` nor
     * `#[IsRegistry]` — which is 44 D0's mechanical test for a ladder that reads over registries it does not
     * own, and the reason its arrays are per-rung sidecars rather than a keyspace.
     *
     * The live specimen is `Splicewire\Tower\Circuit\Capabilities\CapabilityLadder`, which is one tier ABOVE
     * beam and therefore cannot be named from in here — the whole point of ticket 57's decision. It is
     * planted instead, which is stronger anyway: the fixture proves the rule, not the one class.
     */
    public function test_a_position_three_ladder_is_ejected_from_the_population_by_type(): void
    {
        [$audit, $registry] = $this->plant(
            properties: 'private array $manifests = []; private array $overlayManifests = [];',
            methods: 'public function register(string $k, string $v): void { $this->manifests[$k] = $v; }'
                .' public function get(string $k): ?string { return $this->manifests[$k] ?? null; }'
                .' public function rungs(): array { return [\'overlay\', \'base\']; }',
            implements: 'implements \Rushing\Popcorn\Registries\Laddered ',
        );

        $this->assertFalse(
            $audit->isRegistryShaped($registry),
            'Laddered without Registry and without #[IsRegistry] is position 3, not a registry candidate',
        );
        // Ejected from the POPULATION, not merely from the findings: a whitelist would have left the row in
        // `registries()` and silenced the report, which is the shape ticket 57 measured and refused.
        $this->assertSame([], array_column($audit->registries(), 'registry'));
        $this->assertSame([], array_column($audit->undescribed(), 'registry'));
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    /**
     * The other half of ticket 57's guard: the ejection is `Laddered` **without** `Registry`, so a
     * position-2 ladder — a registry that is itself a ladder — stays firmly in the population.
     *
     * Beam owns the estate's position-2 specimen, so this is measured against the real class rather than a
     * fixture. `ParticleResourceRegistry implements Filled, Gated, Laddered, RecordsSupersession, Registry`,
     * and if the ejection were reading `Laddered` alone it would drop out of the shape test here and take
     * its `#[IsRegistry]` obligation with it.
     */
    public function test_a_position_two_ladder_is_still_registry_shaped(): void
    {
        $audit = new UndescribedRegistryAudit([dirname(__DIR__, 2).'/src'], new RegistryIndex);

        $this->assertTrue($audit->isRegistryShaped(ParticleResourceRegistry::class));
    }

    /**
     * Registry-kernel ticket 38 — an array constructor parameter named for a **place** is not an entry path.
     *
     * The planted class is a source SCANNER, which is the live shape this was measured on:
     * `Splicewire\Beam\Dev\Databases\SuiteHarness` takes the directories it reads as `array $roots`, memoizes
     * its parse results in two array properties, hands them back through `sources()`, and has **no write
     * verb anywhere** — nothing registers into it. Criterion 2 fires on the memo caches and criterion 3's
     * lookup half fires on `sources()`, so before this rule the directory list was the only thing standing
     * in for an entry path, and the class entered the population as `outstanding`. It broke the flagship's
     * ratchet at 12 → 13 the day it landed.
     */
    public function test_a_directory_list_constructor_param_is_not_an_entry_path(): void
    {
        [$audit, $registry] = $this->plant(
            properties: 'private ?array $scan = null; private ?array $pins = null;',
            methods: 'public function __construct(private array $roots = []) {}'
                .' public function sources(): array { return $this->scan ??= $this->roots; }'
                .' public function resolveProjectPath(string $path, string $fallback): string { return $path; }',
        );

        $this->assertFalse(
            $audit->isRegistryShaped($registry),
            'a list of directories to scan seeds no entries, so criterion 3 has no entry half',
        );
        // Ejected from the POPULATION, not merely silenced: the row should never have been counted, so it
        // must not reach the artifact as an exemption someone later has to re-argue.
        $this->assertSame([], array_column($audit->registries(), 'registry'));
        $this->assertSame([], array_column($audit->undescribed(), 'registry'));
    }

    /**
     * The other half of that rule, and the reason it is a NAME test rather than a retreat from constructor
     * seeding: a seam that seeds the keyspace names its cargo, and it stays in the population with no write
     * verb at all. `config('data-nav.stages')` arriving as `array $stages` is registration by constructor.
     */
    public function test_an_array_constructor_param_named_for_its_cargo_is_still_an_entry_path(): void
    {
        [$audit, $registry] = $this->plant(
            properties: '',
            methods: 'public function __construct(private array $stages = []) {}'
                .' public function get(string $k): ?string { return $this->stages[$k] ?? null; }',
        );

        $this->assertTrue($audit->isRegistryShaped($registry));
        $this->assertSame([$registry], array_column($audit->undescribed(), 'registry'));
    }
}
