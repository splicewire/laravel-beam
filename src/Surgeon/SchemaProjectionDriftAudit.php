<?php

namespace Splicewire\Beam\Surgeon;

use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Actions\GenerateSchemasAction;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\PathGenerators\DefaultPathGenerator;
use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\SchemaCollection;
use Schemastud\DataSchemas\Support\SchemaFileReader;
use Schemastud\DataSchemas\Support\WrittenSchema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The schema leg's first drift guard (particle-doctrine-followups #14). Of the pre-existing drift
 * audits, four guard TypeScript and two guard the client; the on-disk JSON-Schema projection had
 * NONE — a declared Data class whose schema file was missing or stale was invisible. This audit walks
 * the DECLARED particle set (resource `data:`/`input:` slots, operation `output:` slots including a
 * Stream's event-map values), regenerates each class's schema in memory through the SAME
 * config-driven generator `schemas:generate` runs, and reports:
 *
 *   - a declared class with NO file at its configured disk path — "declared but not emitted";
 *   - a declared class whose disk file no longer equals the regenerated schema — "stale relative to
 *     the declaration".
 *
 * "No schema because nothing was declared" is deliberately NOT this audit's finding — an undeclared
 * surface belongs to the negative-space detector ({@see UndeclaredSurfaceAudit}); this audit only ever
 * walks declarations, so everything it reports is the second failure mode.
 *
 * SCOPE, matching the recorded disk-tree decision (14b): the on-disk tree is the APP's own published
 * schema artifact set — discovery is `data-schemas.auto_discover_types` (default `app/Data`) and no
 * host widens it, so only declared classes whose source file lives under those paths are expected on
 * disk. A package-owned DTO reaches the OpenAPI spec in memory and the TS leg via the transformer,
 * but ships no file in the app's tree — that is the decided scope, not drift, so it is skipped here.
 * Custom path generators (`path_structure: custom`) are not modeled; the audit degrades to a stated
 * skip rather than fabricating paths.
 *
 * Sibling discipline: pure `check()` core over pre-built inputs, findings sorted by class name for
 * byte-stable output, advisory (a regeneration backlog that fails the build is just a blocked build),
 * presence-conditional on schemastud/laravel-data-schemas exactly like {@see SchemaRoundTripAudit}.
 *
 * ONE PIPELINE, NOT TWO. `forApp()` used to hand-roll a second copy of what
 * {@see GenerateSchemasAction} does — instantiate a generator by
 * hardcoded class-name, loop, derive paths, read disk, compare. The two copies DISAGREED, and it
 * cost a real host: `schemas:generate` picks the first generator in `data-schemas.generators` whose
 * `canGenerate()` accepts the class, and `~/Herd/thingsontv` configures
 * `[BlockJsonSchemaGenerator, JsonSchemaGenerator]`. For every Block subclass there, the audit
 * compared disk against output the real command never produces — a permanent phantom "stale"
 * finding no regeneration could clear. So the generator is now resolved from the container
 * ({@see Generator} is bound to a `ChainedGenerator` built from the host's full config), the
 * collection is data-schemas' own {@see FileSchemaCollection}, the disk read is
 * {@see SchemaFileReader}, and the compare is {@see SchemaCollection::diffAgainst()}.
 *
 * ⚠️ `ChainedGenerator::generate()` THROWS when no configured generator accepts a class, where the
 * old hardcoded generator would have tried anyway. `canGenerate()` is therefore asked FIRST, and a
 * refused class is simply absent from the collection — the same "skipped, never fabricated" outcome
 * the old `try`/`catch` produced, reached without letting an advisory audit become a boot-time
 * fatal at exactly the hosts whose config differs from the flagship's.
 *
 * The compare is now STRUCTURAL ({@see SchemaFingerprint}) rather
 * than this class's old key-sorted deep equality. That is strictly the better answer to the question
 * the audit asks: key order was already ignored, and re-worded `description`/`title`/`examples` prose
 * is a doc edit, not a projection that needs regenerating.
 */
class SchemaProjectionDriftAudit implements DoctorAudit
{
    public const CHECK = 'schema.projection-drift';

    /**
     * @param  ?FileSchemaCollection<array-key, WrittenSchema>  $schemas  the in-scope declared classes, generated
     *                                                                    through the host's configured generator chain.
     *                                                                    null ⇒ the check is unavailable (generator not
     *                                                                    installed / unsupported path structure) for $unavailableReason.
     * @param  array<string, array<string, mixed>>  $onDisk  class name => the document read back off disk (absent ⇒ no file)
     * @param  array<string, string>  $declaredBy  class name => the particle declaration site that named it
     */
    public function __construct(
        protected ?FileSchemaCollection $schemas,
        protected ?string $unavailableReason = null,
        protected array $onDisk = [],
        protected array $declaredBy = [],
    ) {}

    public static function forApp(): self
    {
        if (! interface_exists(Generator::class) || ! class_exists(Data::class)) {
            return new self(null, 'data-schemas not installed (base beam is schema-agnostic)');
        }

        $config = (array) config('data-schemas', []);
        $config['output_directory'] ??= resource_path('schemas');

        if (($config['path_structure'] ?? 'namespace') === 'custom') {
            return new self(null, 'a custom path generator is configured — disk paths are not statically derivable here');
        }

        $discoveryPaths = array_map(
            fn ($path) => rtrim((string) $path, '/'),
            (array) ($config['auto_discover_types'] ?? [app_path('Data')]),
        );

        $pathGeneratorClass = $config['path_generator'] ?? DefaultPathGenerator::class;
        $pathGenerator = new $pathGeneratorClass($config);

        try {
            // The container binds this to a ChainedGenerator over the host's whole `generators` list,
            // which is what `schemas:generate` dispatches over. Resolving it is the entire fix for the
            // thingsontv divergence — there is no second copy of the selection rule to drift from.
            $generator = app(Generator::class);
        } catch (\Throwable $e) {
            // A malformed `generators` list is data-schemas' fatal to raise, at the seam a host actually
            // generates from. An advisory audit re-raising it would just crash the doctor sweep.
            return new self(null, 'the configured schema generator could not be built ('.$e->getMessage().')');
        }

        $schemas = new FileSchemaCollection;
        $declaredBy = [];

        foreach (self::declaredClasses() as $class => $site) {
            if (! class_exists($class) || ! is_subclass_of($class, Data::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();

            // The recorded scope decision (14b): only app-tree classes project to disk.
            if ($file === false || ! self::underAny($file, $discoveryPaths)) {
                continue;
            }

            // ASK FIRST. A class no configured generator accepts is its own problem (the round-trip
            // audit's territory), not projection drift — and `generate()` on the chain would THROW.
            if (! $generator->canGenerate($reflection)) {
                continue;
            }

            $schemas->push(new WrittenSchema(
                className: $class,
                schema: $generator->generate($reflection),
                outputPath: $pathGenerator->getSchemaPath($reflection),
            ));

            $declaredBy[$class] = $site;
        }

        return new self($schemas, null, (new SchemaFileReader($config))->documentsFor($schemas), $declaredBy);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        if ($this->schemas === null) {
            return [Finding::pass(self::CHECK, ($this->unavailableReason ?? 'unavailable').' — schema-projection drift check skipped.')];
        }

        return $this->check($this->schemas, $this->onDisk, $this->declaredBy);
    }

    /**
     * The pure core — a generated collection plus documents someone else already read, findings out,
     * no filesystem. `diffAgainst()` is itself pure for exactly this reason.
     *
     * `orphaned` is deliberately unread: {@see SchemaFileReader::documentsFor()} only ever reads the
     * paths this collection names, so an orphan cannot appear — and "a file on disk nobody declared"
     * is the negative-space detector's question, not this one's.
     *
     * @param  FileSchemaCollection<array-key, WrittenSchema>  $schemas
     * @param  array<string, array<string, mixed>>  $onDisk
     * @param  array<string, string>  $declaredBy
     * @return list<Finding>
     */
    public function check(FileSchemaCollection $schemas, array $onDisk, array $declaredBy): array
    {
        $diff = $schemas->diffAgainst($onDisk);

        /** @var array<string, string> $kinds */
        $kinds = [];

        foreach ($diff['missing'] as $class) {
            $kinds[$class] = 'missing';
        }

        foreach ($diff['drifted'] as $class) {
            $kinds[$class] = 'drifted';
        }

        // Byte-stable output: one sort over the union, so the two kinds interleave by class name
        // rather than clustering by kind.
        ksort($kinds);

        $paths = $schemas->toBase()
            ->mapWithKeys(fn (WrittenSchema $schema) => [$schema->className => $schema->outputPath])
            ->all();

        $findings = [];

        foreach ($kinds as $class => $kind) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                $kind === 'missing'
                    ? '%s (declared by %s) has no schema projection at %s — declared but never emitted; run `php artisan schemas:generate`.'
                    : '%s (declared by %s) has a schema projection at %s that is STALE relative to the declaration — regenerate with `php artisan schemas:generate`.',
                $class,
                $declaredBy[$class] ?? 'a particle declaration',
                $paths[$class] ?? '(unknown path)',
            ));
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, $schemas->isEmpty()
                ? 'no declared Data class falls under the disk schema tree\'s scope (app data paths) — nothing to project.'
                : sprintf('%d declared Data class(es) have fresh disk schema projections.', $schemas->count()))];
        }

        return $findings;
    }

    /**
     * Every Data class reachable through a particle declaration site, mapped to the site that declares
     * it (first-declaring site wins for the label; the projection is identical either way).
     *
     * @return array<class-string, string>
     */
    protected static function declaredClasses(): array
    {
        $declared = [];

        foreach (app(ParticleResourceRegistry::class)->all() as $resource) {
            foreach (['data' => $resource->data, 'input' => $resource->input] as $slot => $class) {
                if (is_string($class)) {
                    $declared[$class] ??= "resource '{$resource->key}' {$slot}:";
                }
            }
        }

        foreach (app(ParticleOperationRegistry::class)->all() as $operation) {
            $label = "operation '{$operation->key()}'";

            if (is_string($operation->input)) {
                $declared[$operation->input] ??= "{$label} input:";
            }

            if (is_string($operation->output)) {
                $declared[$operation->output] ??= "{$label} output:";
            } elseif (is_array($operation->output)) {
                foreach ($operation->output as $variants) {
                    foreach ((array) $variants as $class) {
                        if (is_string($class)) {
                            $declared[$class] ??= "{$label} output: (stream)";
                        }
                    }
                }
            }
        }

        return $declared;
    }

    protected static function underAny(string $file, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path !== '' && str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
    }
}
