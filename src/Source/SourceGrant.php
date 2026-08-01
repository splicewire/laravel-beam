<?php

namespace Splicewire\Beam\Source;

use Splicewire\Beam\Schema\SchemaId;

/**
 * The ACTOR for a source-originated write (ADR-0161 Position 4 + MAP §Trust): a bounded grant that
 * identifies the source and enumerates the schema stems it may write. It is the deliberate answer to
 * the "no actor type for external writes" gap — passing a real user would inherit that user's full
 * policy scope (a "shadow anything" arbitrary-write primitive), passing null denies as a guest; a
 * `SourceGrant` is the third thing: authorized for EXACTLY its granted stems, in shadow tiers only.
 *
 * Deny-by-default: an empty allow-list ({@see self::none}) permits nothing, so absence of a grant is
 * refusal, never openness.
 */
class SourceGrant
{
    /**
     * @param  list<string>  $allowedStems  schema stems (or versioned `$id`s) this source may write
     */
    private function __construct(
        public string $sourceId,
        public array $allowedStems,
    ) {}

    /**
     * A grant for `$sourceId` (e.g. a conduit handle or `federation:{authority}`) permitting the given
     * schema stems.
     *
     * @param  list<string>  $allowedStems
     */
    public static function for(string $sourceId, array $allowedStems): self
    {
        return new self($sourceId, array_values($allowedStems));
    }

    /** The deny-by-default actor: identified but authorized for nothing. */
    public static function none(string $sourceId = 'unknown'): self
    {
        return new self($sourceId, []);
    }

    /**
     * May this source write a record of the given schema stem/`$id`? Compared by record type so a
     * versioned `$id` matches a stem allow-list entry and vice versa. Empty allow-list ⇒ false.
     */
    public function permits(string $schema): bool
    {
        if ($schema === '') {
            return false;
        }

        $stem = SchemaId::from($schema)->recordType();

        foreach ($this->allowedStems as $allowed) {
            if ($stem === SchemaId::from($allowed)->recordType()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A source may write ONLY shadow tiers, NEVER {@see SourceWriteTier::Local} — the structural half
     * of the escalation guard (a source can shadow content, never mint/overwrite a locally-owned record).
     * The two-intensity shadow writer (ticket 06) consults this before choosing a tier.
     */
    public function mayWriteTier(SourceWriteTier $tier): bool
    {
        return $tier->isShadow();
    }
}
