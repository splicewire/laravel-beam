<?php

declare(strict_types=1);

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorRegistration;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The beam-doctor aggregation manifest (beam-ux-prototype-extract ticket 08) — the doctor-side mirror of
 * {@see BeamInstallManifest}. A container SINGLETON every beam-* package pushes
 * its own {@see DoctorAudit} into, from its OWN service provider, so ONE `splicewire:beam:doctor` run
 * aggregates every package's readiness checks instead of a per-package `*:doctor` command each.
 *
 * The direction is load-bearing, exactly as on the install side: consumers register DOWN into beam's
 * manifest; **beam-core never learns a consumer's name** (it iterates whatever registered). That keeps the
 * dependency graph acyclic. beam-core's own audits predate the manifest and stay hardcoded in
 * {@see BeamDoctorCommand} (coexist, not migrate — no behaviour change); the
 * manifest carries the consumer tail, which renders after the hardcoded checks (order ascending; `usort`
 * is stable). Each registration carries a gate/advisory flag so a consumer decides whether its own Fail
 * blocks the exit code, honouring the same gate-vs-advisory split beam-core already draws.
 */
final class BeamDoctorManifest
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
