<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The beam-doctor aggregation manifest (beam-ux-prototype-extract ticket 08) — the doctor-side mirror of
 * {@see BeamInstallManifest}. A container SINGLETON every consumer — a beam-* package from its OWN
 * service provider, or the HOST APP from its AppServiceProvider — pushes its {@see DoctorAudit} into,
 * so ONE `splicewire:beam:doctor` run aggregates every registered readiness check instead of a
 * per-consumer `*:doctor` command each. Host registration needs nothing special: `register()` takes
 * plain strings, and the singleton is bound in beam's register phase and read fresh at command
 * invocation — an app's boot runs after every package's register, so a host registering in boot always
 * lands (particle-doctrine-followups ticket 07 deleted the starter workarounds built on the false
 * premise that no such seam existed).
 *
 * The direction is load-bearing, exactly as on the install side: consumers register DOWN into beam's
 * manifest; **beam-core never learns a consumer's name** (it iterates whatever registered). That keeps the
 * dependency graph acyclic. beam-core's own audits predate the manifest and stay hardcoded in
 * {@see BeamDoctorCommand} (coexist, not migrate — no behaviour change); the
 * manifest carries the consumer tail, which renders after the hardcoded checks (order ascending; `usort`
 * is stable). Each registration carries a gate/advisory flag so a consumer decides whether its own Fail
 * blocks the exit code, honouring the same gate-vs-advisory split beam-core already draws.
 */
#[IsRegistry(
    root: 'beam.doctor.audits',
    of: 'per-package readiness audits aggregated by splicewire:beam:doctor',
    arity: RegistryArity::RunAll,
    entryType: DoctorRegistration::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'beam-core\'s OWN audits are deliberately not here — they predate the manifest and stay '
        .'hardcoded in BeamDoctorCommand. This carries the consumer tail only.',
    order: 2,
)]
class BeamDoctorManifest
{
    /** @var list<DoctorRegistration> */
    private array $registrations = [];

    /**
     * Register a package's audit. Idempotent per audit class — re-registering the same audit replaces,
     * so a provider that boots twice (test harness) doesn't double-run it.
     *
     * @param  class-string<DoctorAudit>  $audit
     */
    public function register(string $package, string $audit, bool $gate = false, int $order = 100): void
    {
        $this->registrations = array_values(array_filter(
            $this->registrations,
            static fn (DoctorRegistration $r): bool => $r->audit !== $audit,
        ));

        $this->registrations[] = new DoctorRegistration($package, $audit, $gate, $order);
    }

    /**
     * All registered audits, ordered core-first (ascending {@see DoctorRegistration::$order}, ties keeping
     * registration order — `usort` has been stable since PHP 8.0).
     *
     * @return list<DoctorRegistration>
     */
    public function registrations(): array
    {
        $registrations = $this->registrations;
        usort($registrations, static fn (DoctorRegistration $a, DoctorRegistration $b): int => $a->order <=> $b->order);

        return $registrations;
    }
}
