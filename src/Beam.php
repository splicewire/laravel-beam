<?php

namespace Splicewire\Beam;

use Splicewire\Beam\Doctor\StaticBridgeAudit;
use Splicewire\Beam\Facades\Beam as BeamFacade;

/**
 * TEMPORARY: the deprecated static bridge (beam-facade ticket 05) — NOT the Beam instance.
 *
 * The instance is {@see BeamManager}; the facade is {@see BeamFacade}. This class holds no logic and
 * exists only so unswept call sites — the ones still importing `Splicewire\Beam\Beam` and calling
 * `Beam::table(...)` statically — keep working while the estate is repointed. `composer.local.json`
 * path-links this package live into every co-dev repo, so deleting the statics outright would leave
 * ~289 call sites across 16 repos red from the moment the facade lands until the sweep finishes.
 *
 * **This whole file is deleted by beam-facade ticket 08**, together with the {@see StaticBridgeAudit}
 * that nags about it on every `splicewire:beam:doctor` run. It is a BRIDGE, not the compatibility shim
 * that charting ruled out: a shim is a permanent second way to say the same thing, whereas this one
 * lives between two tickets on one map and never reaches a tagged release.
 *
 * The single-place guarantee survives it — the bridge delegates, it does not resolve the prefix itself.
 *
 * @deprecated beam-facade ticket 05 — import {@see BeamFacade} instead; deleted by ticket 08.
 */
class Beam
{
    /**
     * @deprecated Use the {@see BeamFacade} facade's `table()`.
     */
    public static function table(string $name): string
    {
        return app(BeamManager::class)->table($name);
    }

    /**
     * @deprecated Use the {@see BeamFacade} facade's `tablePrefix()`.
     */
    public static function tablePrefix(): string
    {
        return app(BeamManager::class)->tablePrefix();
    }
}
