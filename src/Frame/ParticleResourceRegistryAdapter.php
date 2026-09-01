<?php

namespace Splicewire\Beam\Frame;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The genuinely stateless bridge from {@see ParticleResourceRegistry} to Frame's agnostic
 * {@see ResourceRegistry} port. Exists ONLY because PHP has no method overloading: this class'
 * `get(): ParticleResource` (REST tier, every existing caller depends on that exact signature) and the
 * port's required `get(): ResourceDefinition` can't both be named `get()` on one class — so this is the
 * one-line-per-method forwarder that lets both shapes exist without either compromising.
 *
 * No storage, no logic, no state of its own — every call passes straight through to
 * {@see ParticleResourceRegistry::hasFramedResource()}/{@see ParticleResourceRegistry::definition()}/
 * {@see ParticleResourceRegistry::definitions()}. Replaces the retired `AdminResourceRegistry`, which
 * carried its OWN parallel `$declarations` store (the thing that made a resource need registering twice —
 * once here, once there); this adapter carries nothing.
 *
 * Named `Adapter`, not `Port`, per ADR-0213 — which this docblock had already argued for two releases
 * before the class was renamed to match it: the port is `ResourceRegistry`, and this is what plugs in.
 *
 * ## The seven kernel methods, and why they forward RAW
 *
 * Frame's port now extends `Rushing\Popcorn\Registries\Registry` (registry-kernel 34 D4), so this class
 * owes the kernel contract as well as the port's sugar. Both halves stay one-line forwards, and the
 * split between them is the whole reason the port declares `Registry<mixed>` rather than
 * `Registry<ResourceDefinition>`:
 *
 * - The **kernel** half is the STORE — `beam.particle.resources`, whose entries are `ParticleResource`
 *   declarations. `resolve()` hands back what is stored, exactly as it does when beam's own registry is
 *   read directly; two objects answering one key must not disagree about what is under it.
 * - The **port** half is the PROJECTION — `get()`/`find()`/`all()` run each stored declaration through
 *   `definition()`/`definitions()`, which is where the realm seam and the framed/REST-only filter live.
 *
 * So this class declares **no `#[IsRegistry]`**. It owns no keyspace: every entry it can answer for is
 * already addressable at `beam.particle.resources`, and a second root over the same entries would be
 * two registries claiming one set of keys — the root collision the attribute exists to make
 * detectable, manufactured on purpose.
 *
 * ## ⚠️ …and the LAST sentence of that paragraph was wrong, which is registry-kernel 77
 *
 * It used to end: *"at a beam host that root simply stands empty, which is the honest reading — beam's
 * resources are beam's."* Measured at `~/Herd/splicewire-app`, `frame.resources` stood at **0** while
 * this adapter answered for **53**, and `ownerOf('frame.resources') === app(port)` was **false** — the
 * root was not reporting *"nothing here"*, it was reporting *"ask somewhere else"*, and the index
 * cannot tell those two apart. It only read as honest to someone who had found this docblock.
 *
 * The reasoning above is still right about what it was choosing between — adapter-declares-a-root vs
 * adapter-declares-nothing — and it never considered the third shape, under which its own objection
 * dissolves. {@see CompositeResourceRegistry} makes `frame.resources` an
 * INDEX whose entries are resource REGISTRIES, so `frame.resources.beam` names *this object* and
 * collides with no resource key at all. Beam therefore still declares no root, and instead ATTACHES
 * itself as a member from `BeamServiceProvider` — which is why the `alias()` that used to sit under
 * this class's `singleton()` is gone.
 *
 * **Nothing about the projection moved, and it must not.** The index ROUTES; this class PROJECTS. A
 * REST-only particle resource is still `has() === false` here and `true` through {@see unfiltered()},
 * read directly or through the index, and `FrameResourcesIndexMembershipTest` pins both paths.
 */
class ParticleResourceRegistryAdapter implements Gated, ResourceRegistry
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    /**
     * Whether `$key` is a FRAMED resource — narrower than the kernel's `has()` on purpose, and
     * unchanged: a REST-only particle resource exists in the store and has no frame projection, so the
     * port must answer `false` for it. Read `unfiltered()->has()` for the store's own answer.
     */
    public function has(RegistryKey|string $key): bool
    {
        return $this->registry->hasFramedResource((string) $key);
    }

    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->registry->register($key, $entry, $by, $ability);

        return $this;
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->registry->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->registry->tryResolve($key);
    }

    /** @return list<mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->registry->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->registry->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->registry->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->registry->authorizeWith($authorizer);

        return $this;
    }

    public function get(string $key): ResourceDefinition
    {
        return $this->registry->definition($key);
    }

    /**
     * The nullable twin the port gained with the kernel (registry-kernel 38). `definition()` throws
     * `InvalidArgumentException` for BOTH an unknown key and a REST-only resource, and `get()` keeps
     * that unchanged; this half answers `null` for either, which is what a caller holding a key it took
     * off a URL wants.
     */
    public function find(string $key): ?ResourceDefinition
    {
        return $this->registry->hasFramedResource($key)
            ? $this->registry->definition($key)
            : null;
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function all(): array
    {
        return $this->registry->definitions();
    }
}
