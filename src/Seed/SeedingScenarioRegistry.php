<?php

namespace Splicewire\Beam\Seed;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Laddered;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * `beam.seed.scenarios` (seeding-scenarios 01): the named Seeding Scenarios `splicewire:beam:seed` chooses
 * between, and the ladder it chooses by.
 *
 * **Who writes it.** Packages register default scenarios from their providers; beam-core registers `baseline`
 * (only baseline steps) and `local` (baseline plus `demo`). A host's `config/beam/seed.php` `scenarios.<name>`
 * overrides a package's scenario of the same name wholesale and wins, and may add names no package registered.
 *
 * **Which one runs** is {@see select()}, a climb over the rungs {@see rungs()} declares, outermost first:
 *
 * 1. `option`: `--scenario=` on the command;
 * 2. `config`: `beam.seed.scenario` (`BEAM_SEED_SCENARIO`);
 * 3. `environment`: the scenario named for `app()->environment()`, when there is one;
 * 4. `baseline`.
 *
 * A name given explicitly (rungs 1 and 2) that no package or host defines is an error, never a quiet fall to
 * `baseline`. An environment with no scenario of its name falls to `baseline`: an unnamed environment gets only
 * baseline data (owner ruling 5). Nothing here reads the environment until {@see select()} is asked.
 *
 * The ladder is declared on the registry rather than climbed by the command so the doctor and the docs read one
 * statement of it. As with every {@see Laddered} registry, the kernel never climbs it; {@see select()} is the one
 * climber. {@see resolve()} by name is not the ladder: it answers the host's override over a package's.
 *
 * @implements Registry<SeedingScenario>
 */
#[IsRegistry(
    root: 'beam.seed.scenarios',
    entryType: SeedingScenario::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'named Seeding Scenarios: which seed steps splicewire:beam:seed runs, by include/exclude lists over step coordinates and memberships. Every scenario runs baseline steps unless it excludes one by coordinate. A host\'s config/beam/seed.php scenarios override a package\'s of the same name. Selected by a declared ladder: --scenario, beam.seed.scenario, the environment\'s name, baseline.',
    order: 4,
)]
class SeedingScenarioRegistry implements Laddered, RecordsRegistrants, Registry
{
    /** @var BasicRegistry<SeedingScenario> */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register a default scenario under `$name`. Supersedes: a later registration of the same name replaces it.
     *
     * @param  SeedingScenario|array{include?: list<string>, exclude?: list<string>}  $scenario
     */
    public function register(RegistryKey|string $name, mixed $scenario = [], ?string $by = null, ?string $ability = null): static
    {
        $name = (string) $name;
        $entry = $scenario instanceof SeedingScenario ? $scenario : SeedingScenario::fromConfig($name, (array) $scenario);

        if ($entry->name !== $name) {
            throw new InvalidArgumentException("Seeding scenario [{$entry->name}] registered under the name [{$name}].");
        }

        $this->store->register($name, $entry, $by, $ability);

        return $this;
    }

    /**
     * The selection ladder's tiers, outermost first. Declarative; {@see select()} climbs them.
     *
     * @return non-empty-list<string>
     */
    public function rungs(): array
    {
        return ['option', 'config', 'environment', SeedStep::BASELINE];
    }

    /**
     * Choose the scenario for one seed run.
     *
     * @param  ?string  $option  `--scenario=`, null or empty when not given
     *
     * @throws InvalidArgumentException when `$option` or `beam.seed.scenario` names a scenario nobody defined
     */
    public function select(?string $option, string $environment): SelectedSeedingScenario
    {
        foreach (['option' => $option, 'config' => config('beam.seed.scenario')] as $rung => $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            // A name from outside (an option, an env var): the nullable read, never has()-then-resolve().
            $scenario = $this->tryResolve($name);

            if (! $scenario instanceof SeedingScenario) {
                $source = $rung === 'option' ? '--scenario' : 'beam.seed.scenario (BEAM_SEED_SCENARIO)';

                throw new InvalidArgumentException(sprintf(
                    'Unknown seeding scenario [%s] from %s. Defined: %s.',
                    $name,
                    $source,
                    implode(', ', $this->names()),
                ));
            }

            return new SelectedSeedingScenario($scenario, $rung);
        }

        $byEnvironment = $environment !== '' ? $this->tryResolve($environment) : null;
        if ($byEnvironment instanceof SeedingScenario) {
            return new SelectedSeedingScenario($byEnvironment, 'environment');
        }

        return new SelectedSeedingScenario(
            $this->tryResolve(SeedStep::BASELINE) ?? new SeedingScenario(SeedStep::BASELINE),
            SeedStep::BASELINE,
        );
    }

    /**
     * Every defined scenario name: the packages' and the host's, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_map(fn (RegistryKey $key): string => $this->nameOf($key), $this->store->keys());
        $names = array_merge($names, array_keys($this->hostScenarios()));
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names);

        return $names;
    }

    /* ---------------- Registry contract ---------------- */

    public function has(RegistryKey|string $key): bool
    {
        return array_key_exists($this->nameOf($key), $this->hostScenarios()) || $this->store->has($key);
    }

    /** The host's scenario of this name when it defines one, else the package default. */
    public function resolve(RegistryKey|string $key): mixed
    {
        $name = $this->nameOf($key);
        $host = $this->hostScenarios();
        if (array_key_exists($name, $host)) {
            return SeedingScenario::fromConfig($name, (array) $host[$name]);
        }

        return $this->store->resolve($key);
    }

    /** The host's scenario of this name when it defines one, else the package default, else null. */
    public function tryResolve(RegistryKey|string $key): mixed
    {
        $name = $this->nameOf($key);
        $host = $this->hostScenarios();
        if (array_key_exists($name, $host)) {
            return SeedingScenario::fromConfig($name, (array) $host[$name]);
        }

        return $this->store->tryResolve($key);
    }

    /** @return list<SeedingScenario> the package-registered scenarios matching `$key` */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> the package-registered names; {@see names()} adds the host's */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function registrantOf(RegistryKey|string $key): ?string
    {
        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->store->registrantOf($key);
    }

    public function keysBy(string $registrant): array
    {
        return $this->store->keysBy($registrant);
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }

    /**
     * A scenario's bare name from a relative name or an absolute key (`beam.seed.scenarios.local` → `local`):
     * the store answers absolute keys, and a host names scenarios bare.
     */
    private function nameOf(RegistryKey|string $key): string
    {
        if ($key instanceof RegistryKey) {
            $segments = $key->segments();

            return (string) end($segments);
        }

        return str_starts_with($key, 'beam.seed.scenarios.') ? substr($key, strlen('beam.seed.scenarios.')) : $key;
    }

    /** @return array<string, mixed> */
    private function hostScenarios(): array
    {
        $scenarios = config('beam.seed.scenarios', []);

        return is_array($scenarios) ? $scenarios : [];
    }
}
