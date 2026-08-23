<?php

use Splicewire\Beam\Http\Particle\ParticleOperationController;

if (! function_exists('set_min_time_limit')) {
    /**
     * Raise the script's max execution time to AT LEAST $seconds, never lowering it.
     *
     * beam-core calls this from {@see ParticleOperationController::runTask()}
     * on the SYNCHRONOUS branch: `?async=false` runs a queueable job inline, holding the request for
     * however long the work takes, so the ceiling has to come up for the duration.
     *
     * It lives here because it was a bare global call into a HOST helper
     * (`splicewire-app/app/helpers.php`) — every `?async=false` particle task fataled with
     * `Call to undefined function` at any beam host that had not happened to define it, which is
     * every host but one, and nothing in the estate reported it (beam-facade ticket 93; found by 75
     * building the newest Task op). `splicewire/tower` met the identical defect first and shipped its
     * own guarded copy; that copy is now deleted in favour of this one, since tower requires beam.
     *
     * The `function_exists` guard is load-bearing in both directions: hosts that already define this
     * (and did so precisely because beam did not) must keep booting, and a redeclaration would fatal
     * exactly the hosts that have been working. First definition wins, harmlessly — the bodies agree.
     *
     * NOTE for anyone landing this at a root: a `files` autoload entry is baked into
     * `vendor/composer/autoload_files.php` at dump time, so a host with an already-installed beam does
     * not pick this up until `composer dump-autoload` runs. The declaration being fixed is not the
     * same event as the root being fixed (74's rule, one tier over).
     */
    function set_min_time_limit(int $seconds): void
    {
        $current = ini_get('max_execution_time');

        if ($current === false || $current < $seconds) {
            ini_set('max_execution_time', (string) $seconds);
        }
    }
}
