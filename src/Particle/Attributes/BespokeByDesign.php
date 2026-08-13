<?php

namespace Splicewire\Beam\Particle\Attributes;

use Attribute;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;
use Splicewire\Beam\Surgeon\ParticleOperationBypassAudit;

/**
 * Marks a hand-wired controller (class target) or a single hand-wired action (method target) as
 * **deliberately bespoke** — a reviewed, documented decision NOT to migrate onto the particle doctrine's
 * generic plumbing (`Route::particleResource()` / `Route::particleOp()`), with the load-bearing reason
 * carried in-code where the audits can read it.
 *
 * The doctrine audits honor it as an ACKNOWLEDGMENT, not an exemption:
 *   - {@see ParticleOperationBypassAudit} (`particle.operation-bypass`) reads the METHOD attribute (falling
 *     back to a CLASS one — "this whole controller is bespoke by design") and downgrades its WARN to a
 *     Pass line that still surfaces `acknowledged: <reason>`;
 *   - {@see ParticleControllerRedundancyAudit} (`particle.controller-redundant`) reads the CLASS attribute
 *     and downgrades the same way.
 *
 * Acknowledged ≠ invisible: the doctor ledger keeps one line per acknowledged site, reason inline, so a
 * reviewer sees every deliberate divergence without re-litigating it on every run. The full reasoning
 * stays in the method/class docblock; `reason:` is its condensed, greppable form. Removing the attribute
 * (or the bespoke shape it defends) re-arms the WARN — nothing is permanently muted.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class BespokeByDesign
{
    /**
     * @param  string  $reason  the condensed why-this-stays-bespoke (surfaced verbatim on the doctor's
     *                          `acknowledged:` line — keep it one honest sentence)
     */
    public function __construct(
        public string $reason,
    ) {}

    /**
     * Resolve the acknowledgment governing a class or one of its methods, or null when none is declared.
     * Method lookup falls back to the class-level attribute (a class-level declaration covers every
     * action). Reflection-based — a non-autoloadable class (pure-unit fixtures) resolves to null, which
     * is exactly the un-acknowledged path.
     */
    public static function on(string $class, ?string $method = null): ?self
    {
        if (! class_exists($class)) {
            return null;
        }

        $ref = new \ReflectionClass($class);

        if ($method !== null && $ref->hasMethod($method)) {
            $attributes = $ref->getMethod($method)->getAttributes(self::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        $attributes = $ref->getAttributes(self::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
