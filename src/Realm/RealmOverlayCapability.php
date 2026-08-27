<?php

namespace Splicewire\Beam\Realm;

/**
 * Frame OS ticket 14 (ADR-0014 §A2): one ENTITLEMENT-GATED capability an overlay adds to a realm.
 *
 * A capability is a named affordance the overlay contributes (an extra action, a launch entry, a tool).
 * It is projected into the realm descriptor's `capabilities` list, and — mirroring the projector's
 * realm-level soft-gate (ADR-0013) — it carries a `locked{reason,upsell}` when the principal does NOT
 * hold `$entitlement`. Present-but-gated, never absent: an unentitled principal still SEES the
 * capability (with the upsell), it just can't exercise it. An UNGATED capability (`$entitlement` null)
 * always projects unlocked.
 */
class RealmOverlayCapability
{
    /**
     * @param  string  $key  the capability's stable key (e.g. `music.render.voice`).
     * @param  string|null  $title  a human label; falls back to the key.
     * @param  string|null  $entitlement  the feature key that gates it; null ⇒ ungated (always unlocked).
     * @param  array<string, mixed>|null  $upsell  the upsell payload shown when locked (title/cta/…).
     * @param  array<string, mixed>  $meta  extra descriptor fields folded into the projected capability.
     */
    public function __construct(
        public string $key,
        public ?string $title = null,
        public ?string $entitlement = null,
        public ?array $upsell = null,
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw  `{key, title?, entitlement?, upsell?, meta?}`
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            key: (string) ($raw['key'] ?? throw new \InvalidArgumentException('A realm overlay capability requires a "key".')),
            title: isset($raw['title']) ? (string) $raw['title'] : null,
            entitlement: isset($raw['entitlement']) ? (string) $raw['entitlement'] : null,
            upsell: isset($raw['upsell']) && is_array($raw['upsell']) ? $raw['upsell'] : null,
            meta: isset($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : [],
        );
    }

    /**
     * Project this capability for a principal into a descriptor array, applying the soft-lock. The
     * shape mirrors the projector's realm-level gate: `{key,title,locked,upsell}` (+ any `meta`), so a
     * launcher renders a locked capability with exactly the affordance it renders a locked realm with.
     *
     * @return array<string, mixed>
     */
    public function project(bool $entitled): array
    {
        $locked = $this->entitlement !== null && ! $entitled;

        return array_merge($this->meta, [
            'key' => $this->key,
            'title' => $this->title ?? $this->key,
            'locked' => $locked,
            'upsell' => $locked ? $this->upsell : null,
        ]);
    }
}
