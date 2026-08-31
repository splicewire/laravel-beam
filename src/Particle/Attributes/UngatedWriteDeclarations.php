<?php

namespace Splicewire\Beam\Particle\Attributes;

use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Doctor\UngatedWriteOperationAudit;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * **The host-free half of the `kind: Write` gate** — every `#[ParticleOp]` declaration under a set of
 * paths that says `kind: OperationKind::Write` and declares no `ability:`, found by reflection over the
 * SOURCE rather than over a booted registry.
 *
 * ## Why this exists next to a doctor audit that answers the same question
 *
 * {@see UngatedWriteOperationAudit} is the enforcement a HOST gets: it reads the booted registry, so it
 * sees imperative `$registry->register(new ParticleOperation(...))` declarations as well as attributes,
 * and it gates `splicewire:beam:doctor`. But a doctor command needs a host, and most `#[ParticleOp]`
 * declarations in this estate are written in a PACKAGE whose suite has no host to boot and — measured
 * 2026-08-31 — usually no `surgeon:audit` either (installed in 2 of 94 packages). A declaring package
 * would therefore learn about its own ungated write op only once some host installed it.
 *
 * "Did this declaration name an ability?" is precisely the class of question AGENTS.md says MAY be
 * fatal: it is answerable from the declaration alone, without knowing which host would load it. So the
 * answer is available one tier earlier than the registry, and this is that tier. A declaring package
 * pins it in five lines:
 *
 * ```php
 * $found = UngatedWriteDeclarations::in(__DIR__.'/../src');
 * $this->assertGreaterThan(0, $found->scanned);   // the guard actually looked
 * $this->assertSame([], $found->unloadable);      // and could see everything it looked at
 * $this->assertSame([], $found->offenders, $found->message());
 * ```
 *
 * ⚠️ **Assert all three, in that order.** This estate's signature defect is *an instrument that reports
 * success by not running*: a scan whose paths are wrong returns `offenders === []` and reads exactly
 * like a clean package. {@see $scanned} and {@see $unloadable} are what tell the two apart, which is
 * why they are public state on the result rather than swallowed inside it.
 *
 * ## What it deliberately does NOT catch
 *
 * - **`kind: Read`, `Task` and `Stream`.** Scoped to `Write` on purpose. {@see OperationKind}'s own
 *   docblock says a Read's gate IS its query scope, so a Read declaring no ability may be entirely
 *   legitimate — sweeping it in here would manufacture findings that have no fix. `Task` is a mutation
 *   and arguably belongs; it is left out because nothing has measured it, and a guard that fails a
 *   build is the wrong place to guess.
 * - **Imperative registrations.** A `new ParticleOperation(...)` inside a service provider carries no
 *   attribute and is invisible to a file scan. That population is the registry audit's, and the two
 *   are complements rather than duplicates.
 * - **Whether the declared ability RESOLVES anywhere.** That is a fact about a host — the exact shape
 *   AGENTS.md says must stay advisory — and it is measured at the host, not here.
 */
final class UngatedWriteDeclarations
{
    /**
     * @param  list<class-string>  $offenders  classes declaring `kind: Write` with no `ability:`
     * @param  array<class-string, string>  $unloadable  what the scan met and could not autoload
     * @param  int  $scanned  how many `#[ParticleOp]`-carrying classes the scan actually reflected
     */
    private function __construct(
        public readonly array $offenders,
        public readonly array $unloadable,
        public readonly int $scanned,
    ) {}

    /**
     * Scan the given source paths (directories or `*.php` files) for ungated write declarations.
     *
     * Paths that do not exist are silently skipped by {@see AttributedClassScanner::classesIn()} — which
     * is why {@see $scanned} is reported rather than assumed.
     */
    public static function in(string ...$paths): self
    {
        $scanner = new AttributedClassScanner;
        $classes = $scanner->scan($paths, ParticleOp::class);

        $offenders = [];
        $scanned = 0;

        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getAttributes(ParticleOp::class) as $attribute) {
                $op = $attribute->newInstance();
                $scanned++;

                // `ability: false` is a DECLARATION (deliberately ungated) and passes, exactly as
                // ParticleOperation::gateUndeclared() reads it. Only `null` — the residue — fails.
                if ($op->kind === OperationKind::Write && $op->ability === null) {
                    $offenders[] = $class;
                }
            }
        }

        return new self(array_values(array_unique($offenders)), $scanner->unloadable(), $scanned);
    }

    /**
     * The failure text, naming the fix rather than only the defect — the shape every doctor finding in
     * this estate takes, because a guard that says only "no" costs its reader the re-derivation.
     */
    public function message(): string
    {
        if ($this->offenders === []) {
            return sprintf('%d #[ParticleOp] declarations scanned; every kind: Write one declares an `ability:`.', $this->scanned);
        }

        return sprintf(
            '%d of %d #[ParticleOp] declarations are `kind: Write` with no `ability:` — neither a token '
            .'nor an explicit `false`. A write operation that names no authorization is gated only by '
            .'whatever middleware its mount happens to carry, and the declaration cannot be read to find '
            ."out which.\n\nDeclare the token the host will grant, or `ability: false` with a docblock "
            .'saying why (the shape `Splicewire\\Beam\\Accounts\\Ops\\StopImpersonating` argues). See '
            .ParticleOperation::class."'s `ability:` docblock.\n%s",
            count($this->offenders),
            $this->scanned,
            implode("\n", array_map(fn (string $class): string => '  '.$class, $this->offenders)),
        );
    }
}
