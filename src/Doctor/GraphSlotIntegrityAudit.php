<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Throwable;

/**
 * Checks that every `graph:` slot names a table and columns its model's connection actually has
 * (graph-operations 04, scope 6).
 *
 * A slot naming a column the model does not have fails only at the first graph read, as a query error. This
 * finds it from the declaration and the schema instead. The generator's own check
 * ({@see \Splicewire\Beam\Console\MakeParticleResourceCommand}) asks the same question through
 * {@see problemsFor()}, so a resource the generator wrote and the doctor read agree.
 */
class GraphSlotIntegrityAudit implements DoctorAudit
{
    public const CHECK = 'particle.graph-slot';

    public function __construct(private ParticleResourceRegistry $resources) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $declared = array_values(array_filter($this->resources->all(), fn (ParticleResource $resource) => $resource->graph !== null));

        if ($declared === []) {
            return [Finding::inconclusive(self::CHECK, 'No resource declares a graph: slot on this host.')];
        }

        $rows = [];

        foreach ($declared as $resource) {
            array_push($rows, ...self::problemsFor($resource));
        }

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d graph: slot%s; every one names a table and columns its model has.',
                count($declared),
                count($declared) === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d graph: slot problem%s: %s',
            count($rows),
            count($rows) === 1 ? '' : 's',
            implode('; ', $rows),
        ))];
    }

    /**
     * What is wrong with one resource's `graph:` slot, read against the schema. Empty when nothing is.
     *
     * @return list<string>
     */
    public static function problemsFor(ParticleResource $resource): array
    {
        if ($resource->graph === null) {
            return [];
        }

        $model = $resource->modelClass();

        if ($model === null || ! is_subclass_of($model, Model::class)) {
            return [sprintf('resource [%s] declares graph: %s on a backing that is not one Eloquent model', $resource->key, $resource->graph->describe())];
        }

        /** @var Model $instance */
        $instance = new $model;
        $problems = [];

        try {
            $schema = Schema::connection($instance->getConnectionName());

            foreach ($resource->graph->references($instance) as $table => $columns) {
                if (! $schema->hasTable($table)) {
                    $problems[] = sprintf('resource [%s] graph: names table `%s`, which does not exist on the [%s] connection', $resource->key, $table, $instance->getConnectionName() ?? config('database.default'));

                    continue;
                }

                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        $problems[] = sprintf('resource [%s] graph: names column `%s.%s`, which does not exist', $resource->key, $table, $column);
                    }
                }
            }
        } catch (Throwable $e) {
            $problems[] = sprintf('resource [%s] graph: could not be checked against the schema: %s', $resource->key, $e->getMessage());
        }

        return $problems;
    }
}
