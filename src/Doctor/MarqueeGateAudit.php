<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;

/**
 * Verifies, at runtime, that the marquee site-mode gate is actually enforced —
 * i.e. the configured `EnforceSiteMode` middleware is present in the effective
 * `web` group. The composer-contract audit only proves marquee is *declared*;
 * this proves it is *wired*. A mode set with nothing enforcing it is the silent
 * "deployed dark but serving live" trap this check turns into a hard failure.
 *
 * Relocated from the satellite doctor (base/shell conformance is owned by beam:doctor); the
 * deploy-dark launch gate is a beam-shell concern, generic across sites. Pure: the command hands
 * it the resolved `web` group + config (read from `beam.marquee.*`) so it stays unit testable
 * without a router.
 */
class MarqueeGateAudit
{
    /**
     * @param  list<string>  $webGroup  the effective `web` middleware group
     */
    public function run(array $webGroup, string $middleware, bool $autoRegister, bool $middlewareExists): Finding
    {
        $check = 'marquee gate enforced in web group';

        if (! $middlewareExists) {
            return Finding::warn(
                $check,
                $middleware.' is not installed/autoloadable, so the site-mode gate cannot enforce — see the marquee-declared check.'
            );
        }

        if (in_array($middleware, $webGroup, true)) {
            return Finding::pass($check, $middleware.' is in the `web` group — `soon` mode is enforced.');
        }

        if ($autoRegister) {
            return Finding::fail(
                $check,
                'auto_register is on but '.$middleware.' is NOT in the `web` group — the mode is set yet nothing enforces it, so a dark deploy still serves the live app.'
            );
        }

        return Finding::warn(
            $check,
            'auto_register is off and '.$middleware.' is not wired into the `web` group — wire it in bootstrap/app.php or a `soon` deploy will serve live.'
        );
    }
}
