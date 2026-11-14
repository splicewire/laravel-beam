<?php

namespace Splicewire\Beam\Seed;

use InvalidArgumentException;

/**
 * A Seeding Scenario (seeding-scenarios 01): a named choice of which seed steps `splicewire:beam:seed` runs, the
 * entry of the `beam.seed.scenarios` registry ({@see SeedingScenarioRegistry}).
 *
 * `include` and `exclude` each list step coordinates (`splicewire/laravel-beam-accounts`) or memberships (`demo`).
 * A step runs when:
 *
 * 1. its coordinate is not in `exclude`, and
 * 2. it is a `baseline` step, or its coordinate or one of its memberships is in `include` and none of its
 *    memberships is in `exclude`.
 *
 * So every scenario runs `baseline` steps, and a single `baseline` step can be removed only by naming its
 * coordinate. `exclude` may not name the `baseline` membership itself: a scenario that runs no baseline steps would
 * strand every package's substrate rows, which is not something one config line should be able to do.
 */
final class SeedingScenario
{
    /** @var list<string> */
    public readonly array $include;

    /** @var list<string> */
    public readonly array $exclude;

    /**
     * @param  array<mixed>  $include  coordinates or memberships this scenario adds to `baseline`; read from host
     *                                 config, so normalized here to unique non-empty strings
     * @param  array<mixed>  $exclude  coordinates or memberships it leaves out; never `baseline`
     */
    public function __construct(
        public readonly string $name,
        array $include = [],
        array $exclude = [],
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A seeding scenario needs a name.');
        }

        $this->include = self::names($include);
        $this->exclude = self::names($exclude);

        if (in_array(SeedStep::BASELINE, $this->exclude, true)) {
            throw new InvalidArgumentException(
                "Seeding scenario [{$name}] excludes the `baseline` membership. Exclude a baseline step by its coordinate instead."
            );
        }
    }

    /**
     * A scenario from a host's `config/beam/seed.php` `scenarios.<name>` entry: `['include' => [...], 'exclude' => [...]]`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self($name, (array) ($config['include'] ?? []), (array) ($config['exclude'] ?? []));
    }

    /** Whether this scenario runs `$step`. */
    public function selects(SeedStep $step): bool
    {
        return $this->leftOutBecause($step) === null;
    }

    /**
     * Why this scenario leaves `$step` out, for the command's report; null when it runs the step.
     */
    public function leftOutBecause(SeedStep $step): ?string
    {
        if (in_array($step->coordinate(), $this->exclude, true)) {
            return 'excluded by coordinate';
        }

        if ($step->belongsTo(SeedStep::BASELINE)) {
            return null;
        }

        $excluded = array_values(array_intersect($step->memberships, $this->exclude));
        if ($excluded !== []) {
            return 'excludes '.implode(', ', $excluded);
        }

        if (in_array($step->coordinate(), $this->include, true) || array_intersect($step->memberships, $this->include) !== []) {
            return null;
        }

        return 'does not include '.implode(', ', $step->memberships);
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private static function names(array $names): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $names), fn (string $n): bool => $n !== '')));
    }
}
