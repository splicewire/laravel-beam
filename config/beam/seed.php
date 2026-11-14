<?php

/*
|--------------------------------------------------------------------------
| beam seed (seeding-scenarios 01)
|--------------------------------------------------------------------------
| Which seed steps `splicewire:beam:seed` runs. A Seeding Scenario is a name plus `include` and `exclude` lists of
| step coordinates (`splicewire/laravel-beam-accounts`) or memberships (`demo`). Every scenario runs the `baseline`
| steps; a baseline step is left out only by naming its coordinate in `exclude`.
|
| beam-core ships `baseline` (baseline steps only) and `local` (baseline plus `demo`). The run's scenario is the first
| of: `--scenario=`, `scenario` below, the scenario named for the environment (`local` on a local machine), `baseline`.
*/

return [

    // The scenario every seed run on this host uses unless `--scenario=` names another. Null: choose by environment,
    // then `baseline`. An unknown name here is an error, not a fall to `baseline`.
    'scenario' => env('BEAM_SEED_SCENARIO'),

    // This host's scenarios, by name. One here replaces a package's scenario of the same name wholesale, and a new
    // name adds one, e.g. a staging host that wants demo data:
    //
    //     'staging' => ['include' => ['demo']],
    //     'local' => ['include' => ['demo'], 'exclude' => ['splicewire/laravel-beam-tenancy']],
    'scenarios' => [],

];
