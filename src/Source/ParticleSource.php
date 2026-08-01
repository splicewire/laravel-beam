<?php

namespace Splicewire\Beam\Source;

/**
 * The typed source descriptor a {@see Contracts\ParticleSourceResolver} returns for a
 * particle reference (ADR-0161 §Decisions.1): the resolved authority ({@see SourceKind})
 * plus the parts each kind carries, so the hydrator/writer route by a decided value
 * instead of re-sniffing the raw string.
 *
 *  - a `conduit` source carries {@see $providerHandle} (the raw `{provider}:{alias}.{tool}`
 *    handle the overlay dereferences);
 *  - a `federated` source carries {@see $authority} (the publisher identity — a federation
 *    grant/tenant) and {@see $externalRef} (the item's id at that authority);
 *  - every foreign source MAY carry {@see $targetSchemaId} — the LOCAL schema `$id` the
 *    foreign payload reconciles onto on read (migrate-on-read handles version skew).
 *
 * A pure value; the named constructors are the only way to build a coherent one, so an
 * `authority`-less federated source or a `providerHandle`-less conduit source can't exist.
 */
class ParticleSource
{
    private function __construct(
        public SourceKind $kind,
        public string $ref,
        public ?string $providerHandle = null,
        public ?string $authority = null,
        public ?string $externalRef = null,
        public ?string $targetSchemaId = null,
    ) {}

    /** A record this tenant owns; the local arm resolves it. */
    public static function local(string $ref, ?string $targetSchemaId = null): self
    {
        return new self(SourceKind::Local, $ref, targetSchemaId: $targetSchemaId);
    }

    /** A third-party tool behind a conduit handle (`{provider}:{alias}.{tool}`, ADR-0110). */
    public static function conduit(string $ref, string $providerHandle, ?string $targetSchemaId = null): self
    {
        return new self(SourceKind::Conduit, $ref, providerHandle: $providerHandle, targetSchemaId: $targetSchemaId);
    }

    /** Another Splicewire tenant's fragment, addressed by authority + external ref (ADR-0126). */
    public static function federated(string $ref, string $authority, string $externalRef, ?string $targetSchemaId = null): self
    {
        return new self(SourceKind::Federated, $ref, authority: $authority, externalRef: $externalRef, targetSchemaId: $targetSchemaId);
    }

    /** True iff this tenant owns the content (routes through the local hydrator arm). */
    public function isLocal(): bool
    {
        return $this->kind === SourceKind::Local;
    }

    /** True iff the source authority is foreign (conduit or federated) — a sourced particle. */
    public function isForeign(): bool
    {
        return ! $this->isLocal();
    }
}
