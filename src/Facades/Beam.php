<?php

namespace Splicewire\Beam\Facades;

use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\BeamManager;

/**
 * The Beam facade — the short static front door to the Beam instance (beam-facade ticket 05).
 *
 * It holds NO logic: every method it appears to have resolves through `__callStatic` to the
 * container-bound {@see BeamManager}, which is where the table-prefix seam prose and the surface itself
 * live. The surface is CLOSED at the four methods below.
 *
 * Deliberately NOT registered as a global alias (`extra.laravel.aliases`): every call site imports this
 * class explicitly, so a bare `\Beam` can never become a second, import-free way to say `Beam::table()`
 * that `surgeon:trace` cannot see.
 *
 * Called before the container is booted — from a published `config/*.php`, say — it THROWS. That is the
 * improvement, not a regression: the static helper it replaces silently fell back to a hardcoded `beam_`
 * and handed a retrofit host wrong table names with no error.
 *
 * The `@method` block below is hand-written and guarded by a reflective parity test
 * (`tests/Facade/FacadeMethodParityTest.php`), which asserts it matches the instance's public methods and
 * that `write()`'s tag matches the reflected `ParticleWriter::write()` signature — so the one place the
 * write signature lives stays the one place.
 *
 * @method static string table(string $name)
 * @method static string tablePrefix()
 * @method static string tableFor(string $configKey, string $stem)
 * @method static \Illuminate\Database\Eloquent\Model write(\Illuminate\Database\Eloquent\Model|string $target, array $payload, mixed $actor = null, ?\Closure $after = null, bool $emit = true)
 *
 * @see BeamManager
 */
class Beam extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeamManager::class;
    }
}
