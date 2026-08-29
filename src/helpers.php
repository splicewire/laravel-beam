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
     * exactly the hosts that have been working. First definition wins, harmlessly.
     *
     * ⚠️ The bodies do NOT agree — this docblock claimed they did until 2026-08-29, and the claim was
     * false in every direction once checked. Five definitions exist estate-wide (grep the real package
     * and host roots, never this repo's symlink view): this one, `splicewire/tower`'s
     * `src/helpers.php` (which the paragraph above says was deleted, and which is still there),
     * `splicewire-app/app/helpers.php`, `prahsys-gateway/app/helpers.php`, and
     * `prognosix-api/app/helpers.php`. Only the last differs textually, and ALL FOUR OTHERS carry the
     * unlimited-ceiling defect fixed below. So "first definition wins" is not harmless at any host that
     * defines its own: it wins with a body that lowers `0` to a finite ceiling. Fixing beam's copy does
     * not reach them — each host copy has to be fixed where it lives, or deleted so this one loads.
     *
     * NOTE for anyone landing this at a root: `composer dump-autoload` is NOT enough, which is the
     * obvious thing to reach for and was measured insufficient. `dump-autoload` regenerates
     * `vendor/composer/autoload_files.php` from `vendor/composer/installed.json` — a SNAPSHOT of each
     * package's `composer.json` taken at install time — so a host whose snapshot predates beam
     * declaring `autoload.files` regenerates the same fileless map forever, silently and successfully.
     * Only a full re-install of the package (`composer update splicewire/laravel-beam`, or a
     * `composer install` against a lock that moved) refreshes `installed.json` and lands the entry.
     * Verify by grepping the host's `autoload_files.php` for `laravel-beam/src/helpers.php`; if it is
     * absent, no amount of dumping will add it. The declaration being fixed is not the same event as
     * the root being fixed (74's rule, one tier over).
     */
    function set_min_time_limit(int $seconds): void
    {
        $current = ini_get('max_execution_time');

        // `0` is PHP's "unlimited", i.e. an INFINITE ceiling — the one value that must never be
        // written down to a finite number. It has to be checked before the comparison below, because
        // `ini_get()` returns the STRING `'0'` and PHP's `'0' < 36000` is true: the guard that exists
        // to never lower a ceiling was, against an unlimited one, the thing that lowered it. Octane
        // hosts run at 0 as a matter of course, so this was the live case, not a theoretical one.
        if ($current !== false && (int) $current === 0) {
            return;
        }

        if ($current === false || $current < $seconds) {
            ini_set('max_execution_time', (string) $seconds);
        }
    }
}
