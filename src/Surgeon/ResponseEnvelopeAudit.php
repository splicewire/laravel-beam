<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Contracts\Container\Container;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;

/**
 * Which wire envelope THIS host actually serves — the outermost layer of its API contract, reported.
 *
 * ## Why an audit at all
 *
 * Measured across `~/Herd/*` on 2026-09-01 (particle-manifest-repatriation ticket 04): the
 * {@see ResponseEnvelope} port was bound by **exactly one host**, and the result was that
 * `~/Herd/splicewire-app` answered `{ success, message, data }` while `~/Herd/tower` answered
 * `{ data }` — same package, same routes, divergent wire contracts, decided by which provider a host
 * happened to run. Nothing could say so, because **a container bind is not auditable**: it has no
 * declaration site anything can read, and the only way to learn the answer was to boot the host and
 * resolve the port by hand. Moving the choice to `beam.core.http.envelope` is what makes this check
 * possible at all, and this check is half of why the key exists.
 *
 * ## What it reports, and why the config value alone is not the answer
 *
 * It resolves the port and compares the RESOLVED class against the configured one, because those two can
 * disagree in both directions and each disagreement is a different fact:
 *
 *  - **a direct host bind outranks the key** — legitimate, and deliberately still supported (a host
 *    wanting a third shape binds the port), but it means the config file no longer describes the wire.
 *    Reported so the discrepancy is visible rather than discovered from a payload.
 *  - **an unusable class-string** — the runtime falls back to {@see ArrayResponseEnvelope} rather than
 *    500ing the whole particle surface, so a mistyped key would otherwise present as a silently
 *    *downgraded* envelope on a host whose clients expect the rich one. That is the estate's recurring
 *    defect class (an instrument that reports success by not running), so it is a WARN.
 *
 * ## Advisory, permanently
 *
 * Which envelope a host should serve is the definition of a host fact — beam's neutral default is
 * correct for a headless beam host and wrong for a tower-backed one, and neither is a grammar error the
 * declaration's author could have gotten right. So this reports and never throws. The one branch that
 * even warns is the misconfiguration, not the choice.
 */
class ResponseEnvelopeAudit implements DoctorAudit
{
    public const CHECK = 'beam.http.envelope';

    public function __construct(protected Container $container) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $configured = config('beam.core.http.envelope');
        $resolved = $this->resolved();

        if ($resolved === null) {
            return [Finding::inconclusive(
                self::CHECK,
                'The ResponseEnvelope port could not be resolved in this host, so the wire shape it serves '
                .'was not measured.'
            )];
        }

        if (! is_string($configured) || ! is_a($configured, ResponseEnvelope::class, true)) {
            return [Finding::warn(
                self::CHECK,
                sprintf(
                    '`beam.core.http.envelope` is %s, which is not a ResponseEnvelope implementation. The '
                    .'runtime fell back to %s rather than fail the particle surface — so this host is '
                    .'serving the NEUTRAL `{ data: … }` shape whatever the key intended.',
                    $this->render($configured),
                    $this->shortly($resolved),
                )
            )];
        }

        if ($resolved !== $configured) {
            return [Finding::warn(
                self::CHECK,
                sprintf(
                    'This host serves %s, but `beam.core.http.envelope` declares %s — a direct container '
                    .'bind of the ResponseEnvelope port is outranking the key. That is supported, and it '
                    .'means the config file no longer describes the wire contract. Point the key at the '
                    .'bound class, or state why the bind is intended.',
                    $this->shortly($resolved),
                    $this->shortly($configured),
                )
            )];
        }

        return [Finding::pass(
            self::CHECK,
            sprintf(
                'This host serves the %s wire shape (%s), declared at `beam.core.http.envelope`%s.',
                $resolved === ArrayResponseEnvelope::class ? 'neutral `{ data: … }`' : 'custom',
                $this->shortly($resolved),
                $resolved === ArrayResponseEnvelope::class ? ' — beam\'s shipped default' : '',
            )
        )];
    }

    /** The class the container actually hands a particle controller, or null if the port will not resolve. */
    protected function resolved(): ?string
    {
        try {
            return $this->container->make(ResponseEnvelope::class)::class;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function shortly(string $class): string
    {
        return str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;
    }

    protected function render(mixed $value): string
    {
        return is_string($value) ? "`{$value}`" : get_debug_type($value);
    }
}
