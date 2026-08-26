<?php

namespace Splicewire\Beam\Particle;

use InvalidArgumentException;
use RuntimeException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RecordsSupersession;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\Superseded;

/**
 * The container-singleton registry of {@see ParticleOperation} declarations, keyed `{resource}.{name}`
 * under the stamped root `beam.particle.operations`.
 *
 * ## It routes through popcorn now, and the entry self-keys
 *
 * Was a hand-rolled `array $operations` keyed `"{$resource}:{$name}"`. Composes a
 * {@see BasicRegistry} instead (held as a FIELD — this class carries REST vocabulary
 * `get()`/`has()`/`find()`/`all()` that no kernel base class could supply, and registry-kernel ticket
 * 01 D1 sanctions composition). The key comes off the entry via {@see ParticleOperation::registryKey()}
 * ({@see HasRegistryKey}), so a one-argument `register($operation)` keeps
 * working and nothing here concatenates a key.
 *
 * ⚠️ **The tracked premise for this migration was wrong, and it is worth not re-deriving.** Both
 * `#[IsRegistry]`'s old note and registry-kernel ticket 05 recorded that `:` is *rejected* by
 * {@see Key}, making this registry *"impossible to migrate by rekeying alone"*. Registry-kernel ticket
 * 30 widened the segment charset and `:` has been legal INSIDE a segment since — `tenants:suspend`
 * parses today, as ONE segment. So the blocker had already dissolved; what remained was not a
 * legality problem but a SHAPE one. A one-segment key is flat, and a flat keyspace cannot answer
 * "what operations does this resource have?" from the key, cannot nest a relation scope
 * (`compositions.cells.approve`), and does not line up segment-for-segment with the dot-segmented
 * permission names `Rushing\PermissionCascade\Support\PermissionNamer::assemble()` emits. Dotting the
 * separator buys all three; it was never about the character being allowed.
 *
 * ## Arity is one step, and stays scalar
 *
 * `<resource>.<name>` is a FLAT keyspace: a read picks one entry by its full address in one step. It
 * is deliberately not `[PickOne, RunAll]` — registry-kernel ticket 47's rule is that arity describes
 * the read path a registry actually ships, and a level with addressable inner entries is keyspace, not
 * a second arity member. Enumerating a resource's operations is `matches('…operations.<resource>')`,
 * which is the same one-step read scoped to a branch.
 */
#[IsRegistry(
    root: 'beam.particle.operations',
    of: 'named particle operations (custom actions) mounted on the generic op controller',
    arity: RegistryArity::PickOne,
    entryType: ParticleOperation::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Keys are `<resource>.<name>` under the stamped root, off the self-keying entry '
        .'({@see ParticleOperation::registryKey()}). The old note said `:` was REJECTED by Key and that '
        .'this registry "cannot be migrated by rekeying alone" (registry-kernel ticket 05) — ⚠️ STALE '
        .'since ticket 30 widened the charset: `:` is legal inside a segment, so `resource:name` always '
        .'parsed, as ONE segment. The migration was about SHAPE (a flat key cannot enumerate a '
        .'resource\'s operations, nest a relation scope, or line up with a dot-segmented permission '
        .'name), not legality. `Supersede` is load-bearing rather than incidental: registering over a '
        .'key is how a package OVERRIDES an operation it does not own, and {@see superseded()} is what '
        .'makes that auditable.',
    order: 13,
)]
class ParticleOperationRegistry implements Gated, RecordsSupersession, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    // ── REST tier (unchanged signatures — every existing caller of this registry is unaffected) ───────

    public function get(string $resource, string $name): ParticleOperation
    {
        return $this->lookup($resource, $name)
            ?? throw new RuntimeException("No particle operation [{$name}] on resource [{$resource}].");
    }

    public function has(RegistryKey|string $resource, string $name = ''): bool
    {
        return $this->lookup($resource, $name) !== null;
    }

    /**
     * The non-throwing twin of {@see get()} — for callers that ASK rather than demand. A codegen pass
     * walking the live route table hits routes whose operation was never registered; that is a
     * reportable absence, not an exception, so it needs a lookup that can say "no".
     */
    public function find(string $resource, string $name): ?ParticleOperation
    {
        return $this->lookup($resource, $name);
    }

    /**
     * A READ that answers "absent" for a key that is not even a legal address, rather than throwing —
     * the same line {@see ParticleResourceRegistry::lookup()} draws. Registration still throws on a
     * malformed key (a declaration that cannot be addressed is a defect and must be loud); a LOOKUP
     * asked about something that is not here must answer "not here", not 500.
     */
    private function lookup(RegistryKey|string $resource, string $name): ?ParticleOperation
    {
        $key = $resource instanceof RegistryKey
            ? $resource
            : ($name === '' ? $resource : "{$resource}.{$name}");

        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->entries->tryResolve($key);
    }

    /**
     * Every registered operation, registration order — for auditors walking the DECLARED set rather
     * than looking one up (the schema-projection drift audit, particle-doctrine-followups 14).
     *
     * @return list<ParticleOperation>
     */
    public function all(): array
    {
        return $this->entries->matches($this->entries->root());
    }

    /**
     * Every operation declared on one resource, registration order.
     *
     * This read is what the dotted key bought and the colon could not express: `<resource>.<name>` is a
     * branch address, so a resource's operations are a segment-wise `matches()` rather than a scan with
     * a string prefix test.
     *
     * ⚠️ **This docblock used to say `Route::particleResource()` reads through here. It does not**, and
     * the claim was describing ticket 01's plan rather than the code (corrected on that ticket's
     * resolution, 2026-08-26). `ParticleMounter::resource()` mounts the five CRUD verbs off an
     * `in_array($only)` check and never touches this registry — its only registry read is
     * `DataFilter::registry()`. The sole caller is
     * `Splicewire\Beam\Particle\Mount\PendingParticleMount::register()`, i.e. `->ops(true)` on the
     * fluent mount, whose opt-in is deliberate: a package that ships a `#[ParticleOp]` class must never
     * be able to add a public route to a host that did not ask for one.
     *
     * @return list<ParticleOperation>
     */
    public function forResource(string $resource): array
    {
        if (Key::tryParse($resource) === null) {
            return [];
        }

        return $this->entries->matches($resource);
    }

    // ── The kernel contract (registry-kernel ticket 53) ─────────────────────────────────────────────

    /**
     * Store an operation. The one-argument spelling `register($operation)` is the historical call and
     * stays; the contract spelling `register('tenants.suspend', $operation, by: …)` works too, which is
     * what lets a {@see Registrar} fill this registry at all.
     *
     * The key is never concatenated here. When the entry is handed in whole it keys ITSELF, which is
     * the seam that makes a root change a one-line edit on the attribute above.
     */
    public function register(RegistryKey|string|ParticleOperation $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof ParticleOperation) {
            $operation = $key;
            $address = $operation->registryKey();
        } else {
            $operation = $entry;
            $address = $key;

            if (! $operation instanceof ParticleOperation) {
                throw new InvalidArgumentException(sprintf(
                    'ParticleOperationRegistry stores ParticleOperation declarations; `%s` was given for key [%s].',
                    get_debug_type($entry),
                    (string) $key,
                ));
            }
        }

        $this->entries->register($address, $operation, $by, $ability);

        return $this;
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    /** @return list<ParticleOperation> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->entries = $this->entries->unfiltered();

        return $unfiltered;
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The entries displaced at `$key`, oldest first — what makes "a package overrode this operation"
     * a fact a reader can check rather than an inference from provider boot order.
     *
     * @return list<Superseded>
     */
    public function superseded(RegistryKey|string $key): array
    {
        return $this->entries->superseded($key);
    }
}
