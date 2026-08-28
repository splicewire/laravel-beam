<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\ClassKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
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
/**
 * @implements Registry<DoctorRegistration>
 */
class BeamDoctorManifest implements Registry
{
    /** @var BasicRegistry<DoctorRegistration> */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register a package's audit. Idempotent per audit class — re-registering the same audit replaces,
     * so a provider that boots twice (test harness) doesn't double-run it.
     *
     * @param  class-string<DoctorAudit>  $audit
     */
    public function register(
        RegistryKey|string $package,
        mixed $audit = null,
        ?string $by = null,
        ?string $ability = null,
        bool $gate = false,
        int $order = 100,
    ): static {
        // ⚠️ The KEY is the audit class, not the package — this manifest has always been idempotent
        // per audit ("re-registering the same audit replaces"), and one package ships many audits.
        // `ClassKey` carries the full namespace, so two audits sharing a basename across packages
        // cannot silently supersede one another under `Supersede`; `Key::fromClass()` would reduce
        // both to the same segment.
        //
        // `$package` stays slot 1 and keeps its name because 44 call sites spell it that way. That
        // makes slot 1 the contract's `$key` position while carrying the package, which is a
        // deliberate divergence recorded here rather than a rename that would break every caller.
        $this->store->register(ClassKey::of((string) $audit), new DoctorRegistration((string) $package, (string) $audit, $gate, $order), $by, $ability);

        return $this;
    }

    /**
     * All registered audits, ordered core-first (ascending {@see DoctorRegistration::$order}, ties keeping
     * registration order — `usort` has been stable since PHP 8.0).
     *
     * @return list<DoctorRegistration>
     */
    public function registrations(): array
    {
        $registrations = array_values(array_map(
            fn (RegistryKey $key): mixed => $this->store->resolve($key),
            $this->store->keys(),
        ));

        usort($registrations, static fn (DoctorRegistration $a, DoctorRegistration $b): int => $a->order <=> $b->order);

        return $registrations;
    }

    /* ---------------- Registry contract ---------------- */

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return array<string, mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }
}