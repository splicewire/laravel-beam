<?php

namespace Splicewire\Beam\Surgeon;

use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
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
 * Sibling discipline: pure `check()` core over pre-built rows, rows sorted by class name for
 * byte-stable output, advisory (a regeneration backlog that fails the build is just a blocked build),
 * presence-conditional on schemastud/laravel-data-schemas exactly like {@see SchemaRoundTripAudit}.
 */
class SchemaProjectionDriftAudit implements DoctorAudit
{
    public const CHECK = 'schema.projection-drift';

    /**
     * @param  ?list<array{class: string, declaredBy: string, path: string, disk: ?string, generated: ?array<string, mixed>}>  $rows
     *                                                                                                                                null ⇒ the check is unavailable (generator not installed / unsupported path structure) for $unavailableReason.
     */
    public function __construct(
        protected ?array $rows,
        protected ?string $unavailableReason = null,
    ) {}

    public static function forApp(): self
    {
        $generatorClass = 'Schemastud\\DataSchemas\\Generators\\JsonSchemaGenerator';

        if (! class_exists($generatorClass) || ! class_exists(Data::class)) {
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

        $pathGeneratorClass = $config['path_generator'] ?? 'Schemastud\\DataSchemas\\PathGenerators\\DefaultPathGenerator';
        $pathGenerator = new $pathGeneratorClass($config);
        $generator = new $generatorClass($config);

        $rows = [];

        foreach (self::declaredClasses() as $class => $declaredBy) {
            if (! class_exists($class) || ! is_subclass_of($class, Data::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();

            // The recorded scope decision (14b): only app-tree classes project to disk.
            if ($file === false || ! self::underAny($file, $discoveryPaths)) {
                continue;
            }

            $path = $pathGenerator->getSchemaPath($reflection);

            try {
                $generated = $generator->generate($reflection);
            } catch (\Throwable) {
                // A class the generator cannot process is its own problem (the round-trip audit's
                // territory), not projection drift — degrade, never fabricate.
                $generated = null;
            }

            $rows[] = [
                'class' => $class,
                'declaredBy' => $declaredBy,
                'path' => $path,
                'disk' => is_readable($path) ? (string) file_get_contents($path) : null,
                'generated' => is_array($generated) ? $generated : null,
            ];
        }

        return new self($rows);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        if ($this->rows === null) {
            return [Finding::pass(self::CHECK, ($this->unavailableReason ?? 'unavailable').' — schema-projection drift check skipped.')];
        }

        return $this->check($this->rows);
    }

    /**
     * The pure core — pre-built rows in, findings out, no filesystem.
     *
     * @param  list<array{class: string, declaredBy: string, path: string, disk: ?string, generated: ?array<string, mixed>}>  $rows
     * @return list<Finding>
     */
    public function check(array $rows): array
    {
        usort($rows, fn ($a, $b) => $a['class'] <=> $b['class']);

        $findings = [];

        foreach ($rows as $row) {
            if ($row['generated'] === null) {
                continue;
            }

            if ($row['disk'] === null) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s (declared by %s) has no schema projection at %s — declared but never emitted; run `php artisan schemas:generate`.',
                    $row['class'],
                    $row['declaredBy'],
                    $row['path'],
                ));

                continue;
            }

            if (json_decode($row['disk'], true) !== $row['generated']) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s (declared by %s) has a schema projection at %s that is STALE relative to the declaration — regenerate with `php artisan schemas:generate`.',
                    $row['class'],
                    $row['declaredBy'],
                    $row['path'],
                ));
            }
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, $rows === []
                ? 'no declared Data class falls under the disk schema tree\'s scope (app data paths) — nothing to project.'
                : sprintf('%d declared Data class(es) have fresh disk schema projections.', count($rows)))];
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
