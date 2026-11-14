<?php

namespace Splicewire\Beam\Seed;

use InvalidArgumentException;
use Splicewire\Beam\Install\InstallStep;

/**
 * One beam-* package's seed step in the {@see BeamSeedManifest} — the seed-side twin of
 * {@see InstallStep}: a `db:seed`-runnable seeder class, an ordering hint so
 * `splicewire:beam:seed` runs core-first then consumers, the memberships a Seeding Scenario selects it by, and an
 * optional config gate for "this step cannot run here".
 *
 * **Memberships** (seeding-scenarios 01) say what kind of data the step writes. A step registered without any is a
 * `baseline` step, which every {@see SeedingScenario} runs unless it excludes the step by coordinate; a demo step
 * says `demo`, which only a scenario that includes it runs. Which environment gets demo data is the scenario's
 * question, never the step's: a step does not read the environment.
 */
class SeedStep
{
    /** The membership every scenario runs. A step registered with no membership is in it. */
    public const BASELINE = 'baseline';

    /** The membership for fabricated demo subjects (users, teams, a tenant). */
    public const DEMO = 'demo';

    /** @var non-empty-list<string> */
    public array $memberships;

    /**
     * @param  string  $package  the coordinate the step was registered under: the registering package's composer
     *                           name, or a sub-coordinate under it for a second step. Printed by the command, and what a
     *                           scenario's `include`/`exclude` match by coordinate; beam never branches on it
     * @param  class-string  $seeder  the seeder class run via `db:seed --class`
     * @param  int  $order  lower runs first; beam-core registers at 0 (core-first), consumers default to 100
     * @param  ?string  $configGate  a config key that must be truthy for this step to run (`config($gate)`);
     *                               null ⇒ always runs. For "this step cannot run here" (an estate this host declared
     *                               absent), not for which environment gets demo data: that is a membership
     * @param  string|list<string>  $memberships  defaults to `baseline`
     */
    public function __construct(
        public string $package,
        public string $seeder,
        public int $order = 100,
        public ?string $configGate = null,
        string|array $memberships = self::BASELINE,
    ) {
        $memberships = array_values(array_unique(array_map('strval', (array) $memberships)));

        if ($memberships === [] || in_array('', $memberships, true)) {
            throw new InvalidArgumentException("Seed step [{$package}] must name at least one non-empty membership.");
        }

        $this->memberships = $memberships;
    }

    public function belongsTo(string $membership): bool
    {
        return in_array($membership, $this->memberships, true);
    }

    /** The step's coordinate, the key a scenario's `include` and `exclude` match it by. */
    public function coordinate(): string
    {
        return $this->package;
    }
}
