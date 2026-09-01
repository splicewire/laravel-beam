<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Throwable;

/**
 * A class named in the host's explicit resource list — `beam.core.resources.classes`, falling back to
 * `frame.resources` — is registered FIRST, so anything registered afterwards displaces it.
 *
 * ## The asymmetry this exists to make audible
 *
 * Beam documents two ways a host overrides a package's `#[ParticleResource]`, and they have opposite
 * outcomes. {@see BeamServiceProvider::discoverResources()} reads the explicit list
 * and registers it before beam's own cached manifest or live scan, and — because it runs inside beam's
 * `boot()` — before every OTHER family package's provider boots. `ParticleResourceRegistry` declares
 * `onDuplicate: OnDuplicate::Supersede`, so the last writer takes the key. Therefore:
 *
 * - hand-registered from the host's own provider `boot()` → runs last → **wins**. This is the route
 *   {@see ParticleResourceRegistry::attach()} describes, correctly, for that route only.
 * - named in the explicit list → registered first → **loses to anything that registers the same key
 *   later**, silently. No error, no warning; the host simply gets the package's class.
 *
 * Nothing about writing the config line says which of the two you are on. That is the whole finding
 * (registry-kernel ticket 67), and it is arrival order behaving exactly as ticket 19 D1 settled it
 * should — so the repair is to REPORT the displacement, not to introduce a precedence tier.
 *
 * ## Four verdicts, because "was it displaced?" alone is 75% noise
 *
 * Measured 2026-08-28 across the seven roots with a non-empty list (31 entries): a bare
 * displaced-or-not check fires on 18 of them and only a handful are anything. The identity of the
 * displaced entry is the signal:
 *
 *  - **`resource.listing.displaced`** — the listed class is NOT what resolves. The defect: the host
 *    wrote a line asking for its own class and is being served the package's. Zero live instances at
 *    the time of writing, which is exactly why it needs an instrument rather than a memory.
 *  - **`resource.listing.rescued`** — the listed class DOES resolve, but a different class displaced
 *    it along the way, so the listing itself lost and a later registration happened to re-register the
 *    same class. The outcome is correct today and depends on the boot order of two packages neither of
 *    which knows about the other. Live at `~/Herd/splicewire-app` on `tokens` and `invitations`: the
 *    config lists tower's `TokenResourceData`, `laravel-beam-accounts` boots after beam and registers
 *    its own `TokenData` over it, and tower's `TowerFrameResourceProvider` boots later still and puts
 *    the right class back. Reorder those two packages and the flagship's `tokens` resource silently
 *    becomes beam-accounts'.
 *  - **`resource.listing.redundant`** — the listed class resolves and every entry it displaced is the
 *    SAME class: the config registers it, something else registers it again, and the entry is displaced
 *    by itself. Harmless; the line can go. 16 of the 31 measured entries.
 *  - **sole registration** — nothing displaced it, so the listing is the only thing putting that
 *    resource in the registry. Load-bearing, and silent. The estate holds a live counter-example to any
 *    "you listed something already attributed" check: `~/Herd/splicewire`'s `WorkflowAwaitingRowData`
 *    is attributed in `laravel-beam-workflows` and the scan does not reach it, so the listing is the
 *    only registration there is.
 *
 * ## Advisory, permanently
 *
 * The population is a HOST fact — which classes this host lists, and which packages it installs
 * alongside them — which is `rushing/laravel-doctor`'s textbook advisory case. Nothing here is grammar
 * the listing's author could have gotten right without knowing what else the host composes. A host
 * that wants it to gate registers this class in its own manifest with `gate: true`.
 */
class ListedResourceDisplacementAudit implements DoctorAudit
{
    public function __construct(
        protected ParticleResourceRegistry $registry,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $listed = config('beam.core.resources.classes', config('frame.resources', []));

        if (! is_array($listed) || $listed === []) {
            return [Finding::inconclusive(
                'resource.listing',
                'This host names no explicit resource classes (`beam.core.resources.classes`, falling '
                .'back to `frame.resources`), so there is no listing to displace. Nothing was measured.'
            )];
        }

        $findings = [];
        $sole = 0;
        $redundant = 0;

        foreach ($listed as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $key = $this->keyFor($class);

            if ($key === null) {
                continue;
            }

            $winner = $this->registry->find($key);

            if (! $winner instanceof ParticleResource) {
                continue;
            }

            $winningClass = $winner->data;
            $displacedBySomethingElse = false;

            foreach ($this->registry->superseded($key) as $superseded) {
                $displaced = $superseded->entry instanceof ParticleResource ? $superseded->entry->data : null;

                if ($displaced !== $winningClass) {
                    $displacedBySomethingElse = true;
                }
            }

            if ($winningClass !== $class) {
                $findings[] = Finding::warn(
                    'resource.listing.displaced',
                    "[{$key}] is listed as {$class} but resolves {$winningClass}. The explicit list is "
                    .'registered before beam\'s own manifest/scan and before every other package\'s '
                    .'provider boots, so under OnDuplicate::Supersede the listing loses. Move this '
                    .'override to the host\'s own provider boot() — `app('
                    .ParticleResourceRegistry::class.')->registerClass('
                    .class_basename($class).'::class)` — which runs last and wins.'
                );

                continue;
            }

            if ($displacedBySomethingElse) {
                $findings[] = Finding::warn(
                    'resource.listing.rescued',
                    "[{$key}] resolves the listed {$class}, but the listing itself was displaced and "
                    .'only a LATER registration of the same class put it back. The outcome depends on '
                    .'the boot order of two packages, not on this config line. Register it from the '
                    .'host\'s own provider boot() so it wins by construction, and drop the list entry.'
                );

                continue;
            }

            if ($this->registry->superseded($key) !== []) {
                $redundant++;

                continue;
            }

            $sole++;
        }

        // The census is reported the same way whether or not anything warned. Redundancy is NOT a warn:
        // a listing displaced by its own class resolves the class the host asked for, so raising it to a
        // warning would make four of the estate's seven listing roots yellow over a deletable line —
        // exactly the 75%-false-positive reading a bare displaced-or-not check gives.
        $findings[] = Finding::pass(
            'resource.listing',
            sprintf(
                '%d explicitly listed resource %s: %d sole registration, %d re-registered by the same '
                .'class (redundant — the line can be deleted), %d displaced or boot-order-dependent.',
                count($listed),
                count($listed) === 1 ? 'class' : 'classes',
                $sole,
                $redundant,
                count($findings),
            )
        );

        return $findings;
    }

    /**
     * The resource key a `#[ParticleResource]` class declares, or null when the class carries no
     * attribute (it is then registered some other way and this audit has nothing to say about it).
     */
    protected function keyFor(string $class): ?string
    {
        try {
            return (string) AttributedParticleDiscovery::resourceFromAttribute($class)->key;
        } catch (Throwable) {
            return null;
        }
    }
}
