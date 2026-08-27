<?php

namespace Splicewire\Beam\Surface;

use Splicewire\Beam\Surface\Data\ResourceSeamData;
use Splicewire\Beam\Surface\Data\ResourceSeamInventoryData;
use Splicewire\Beam\Surface\Data\RoutePostureData;
use Splicewire\Beam\Surface\Data\SeamCorroborationData;
use Splicewire\Beam\Surface\Data\SurfaceFindingData;

/**
 * Composes a parsed document ({@see SpecSource}) with a live runtime's posture
 * ({@see RuntimeCorroborator}) into per-seam agreement, disagreement, and gap.
 *
 * **A plain service, deliberately not named `Particle*`.** It is a mechanism; only the boundary crossing
 * that exposes it gets declared. It also carries no compliance vocabulary — no control, no criterion, no
 * evidence — so it is usable with no SOC 2 concept anywhere in scope. That is not fastidiousness: the
 * whole product argument is that this reads a *foreign* vendor's spec, and a mechanism that needs a
 * control framework to run cannot.
 *
 * ## Two entry points, one vocabulary
 *
 * {@see corroborate()} needs a runtime and produces findings ranked {@see SurfaceFindingData::RANK_OBSERVED}.
 * {@see audit()} needs only the document and produces the same *kinds* of finding floored to
 * {@see SurfaceFindingData::RANK_DOCUMENTED}. There is no second verdict language for the weak case, and
 * nothing unverifiable is swept into a gap — a wall of gaps reads to a skimming reader as "no
 * violations", which is the failure this shape exists to prevent.
 */
class OpenApiSpecCorroborator
{
    public function __construct(private RuntimeCorroborator $runtime) {}

    /**
     * The strong path: what the document claims, checked against what the router does.
     */
    public function corroborate(ResourceSeamInventoryData $inventory): SeamCorroborationData
    {
        $postures = $this->runtime->posture();
        $agreements = [];
        $disagreements = [];
        $gaps = [];
        $matched = [];

        foreach ($inventory->seams as $seam) {
            $key = SurfaceSignature::normalize($seam->signature());
            $posture = $postures[$key] ?? null;

            if ($posture === null) {
                $disagreements[] = new SurfaceFindingData(
                    kind: SurfaceFindingData::KIND_DOCUMENTED_BUT_UNROUTED,
                    signature: $seam->signature(),
                    facet: 'presence',
                    documented: 'present',
                    observed: 'absent',
                    provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                );

                continue;
            }

            $matched[$key] = true;

            foreach ($this->compareSeam($seam, $posture) as $finding) {
                match ($finding->kind) {
                    SurfaceFindingData::KIND_AGREEMENT => $agreements[] = $finding,
                    SurfaceFindingData::KIND_UNDECLARED_SECURITY,
                    SurfaceFindingData::KIND_UNDETERMINED_FACET => $gaps[] = $finding,
                    default => $disagreements[] = $finding,
                };
            }
        }

        foreach ($postures as $key => $posture) {
            if (! isset($matched[$key])) {
                $disagreements[] = new SurfaceFindingData(
                    kind: SurfaceFindingData::KIND_UNDOCUMENTED_SURFACE,
                    signature: $posture->signature,
                    facet: 'presence',
                    documented: 'absent',
                    observed: 'present',
                    provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                    location: $posture->name,
                );
            }
        }

        // The undeclared-SHAPE direction is the negative-space audit's answer, read rather than
        // recomputed. It is a gap, not a disagreement: the document and the router do not contradict
        // each other about a route whose shape nobody declared — nobody said anything at all.
        foreach ($this->runtime->undeclared() as $row) {
            // One finding per VERB. An earlier draft emitted `GET|POST /x` as a single signature, which
            // is a shape no other finding in the system uses and which `SurfaceSignature::normalize()`
            // can never match against a route or a seam — so those findings could not be correlated with
            // anything, which is most of what a finding is for.
            foreach ($row['methods'] as $method) {
                $gaps[] = new SurfaceFindingData(
                    kind: SurfaceFindingData::KIND_UNDECLARED_SHAPE,
                    signature: SurfaceSignature::compose($method, $row['uri']),
                    facet: 'shape',
                    documented: 'none',
                    observed: $row['tier'],
                    provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                    location: $row['location'],
                );
            }
        }

        return new SeamCorroborationData(
            mode: SeamCorroborationData::MODE_CORROBORATED,
            agreements: $this->sorted($agreements),
            disagreements: $this->sorted($disagreements),
            gaps: $this->sorted($gaps),
            specSeamCount: $inventory->count(),
            runtimeRouteCount: count($postures),
        );
    }

    /**
     * The weak path: the document alone. Real findings — a spec that never says whether an endpoint is
     * secured is a genuine declaration defect, and saying so is worth money to whoever wrote it — but
     * every one of them is floored to {@see SurfaceFindingData::RANK_DOCUMENTED} and can never present
     * as strongly-evidenced.
     */
    public function audit(ResourceSeamInventoryData $inventory): SeamCorroborationData
    {
        $agreements = [];
        $gaps = [];
        $disagreements = [];

        foreach ($inventory->seams as $seam) {
            if ($seam->security === null) {
                $gaps[] = new SurfaceFindingData(
                    kind: SurfaceFindingData::KIND_UNDECLARED_SECURITY,
                    signature: $seam->signature(),
                    facet: PostureFacet::AuthRequired->value,
                    documented: 'undeclared',
                    observed: null,
                    provenanceRank: SurfaceFindingData::RANK_DOCUMENTED,
                );

                continue;
            }

            // A named scheme the document never defines is a defect readable from the document alone —
            // the one class of disagreement a spec can have with itself.
            $undefined = array_values(array_diff($seam->security, $inventory->securitySchemes));

            if ($undefined !== []) {
                $disagreements[] = new SurfaceFindingData(
                    kind: SurfaceFindingData::KIND_UNRESOLVABLE_GATE,
                    signature: $seam->signature(),
                    facet: PostureFacet::AuthorizationPolicy->value,
                    documented: implode(',', $undefined),
                    observed: 'no such security scheme in this document',
                    provenanceRank: SurfaceFindingData::RANK_DOCUMENTED,
                );

                continue;
            }

            $agreements[] = new SurfaceFindingData(
                kind: SurfaceFindingData::KIND_AGREEMENT,
                signature: $seam->signature(),
                facet: PostureFacet::AuthRequired->value,
                documented: $seam->claimsAuthentication() ? 'required' : 'public',
                observed: null,
                provenanceRank: SurfaceFindingData::RANK_DOCUMENTED,
            );
        }

        return new SeamCorroborationData(
            mode: SeamCorroborationData::MODE_SPEC_ONLY,
            agreements: $this->sorted($agreements),
            disagreements: $this->sorted($disagreements),
            gaps: $this->sorted($gaps),
            specSeamCount: $inventory->count(),
            runtimeRouteCount: 0,
        );
    }

    /**
     * One documented seam against its live route.
     *
     * @return list<SurfaceFindingData>
     */
    private function compareSeam(ResourceSeamData $seam, RoutePostureData $posture): array
    {
        $findings = [];
        $authObserved = $posture->facet(PostureFacet::AuthRequired);

        if ($seam->security === null) {
            $findings[] = new SurfaceFindingData(
                kind: SurfaceFindingData::KIND_UNDECLARED_SECURITY,
                signature: $seam->signature(),
                facet: PostureFacet::AuthRequired->value,
                documented: 'undeclared',
                observed: $this->describe($authObserved),
                provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                location: $posture->name,
            );
        } elseif ($authObserved === null) {
            $findings[] = $this->undetermined($seam->signature(), PostureFacet::AuthRequired, $posture);
        } elseif ($seam->claimsAuthentication() && ! $authObserved) {
            // The headline finding: the document promises a gate the router does not have.
            $findings[] = new SurfaceFindingData(
                kind: SurfaceFindingData::KIND_DOCUMENTED_AUTHENTICATED_BUT_OPEN,
                signature: $seam->signature(),
                facet: PostureFacet::AuthRequired->value,
                documented: implode(',', $seam->security),
                observed: 'no authentication middleware',
                provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                location: $posture->name,
            );
        } elseif (! $seam->claimsAuthentication() && $authObserved) {
            $findings[] = new SurfaceFindingData(
                kind: SurfaceFindingData::KIND_DOCUMENTED_PUBLIC_BUT_GATED,
                signature: $seam->signature(),
                facet: PostureFacet::AuthRequired->value,
                documented: $seam->security === [] ? 'public' : 'optional',
                observed: 'authentication middleware present',
                provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                location: $posture->name,
            );
        } else {
            $findings[] = new SurfaceFindingData(
                kind: SurfaceFindingData::KIND_AGREEMENT,
                signature: $seam->signature(),
                facet: PostureFacet::AuthRequired->value,
                documented: $seam->claimsAuthentication() ? 'required' : 'public',
                observed: $this->describe($authObserved),
                provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                location: $posture->name,
            );
        }

        // A declared ability with nothing behind it — the particle pipeline said "gate this" and the
        // resolved surface has no gate. Reported separately from authentication because an authenticated
        // ungated endpoint is a different failure from an anonymous one.
        if ($posture->ability !== null && $posture->facet(PostureFacet::AuthorizationPolicy) === false) {
            $findings[] = new SurfaceFindingData(
                kind: SurfaceFindingData::KIND_UNRESOLVABLE_GATE,
                signature: $seam->signature(),
                facet: PostureFacet::AuthorizationPolicy->value,
                documented: $posture->ability,
                observed: 'no resolvable gate',
                provenanceRank: SurfaceFindingData::RANK_OBSERVED,
                location: $posture->name,
            );
        }

        foreach ($posture->undeterminedFacets() as $facet) {
            if ($facet === PostureFacet::AuthRequired) {
                continue; // already reported above
            }

            $findings[] = $this->undetermined($seam->signature(), $facet, $posture);
        }

        return $findings;
    }

    private function undetermined(string $signature, PostureFacet $facet, RoutePostureData $posture): SurfaceFindingData
    {
        return new SurfaceFindingData(
            kind: SurfaceFindingData::KIND_UNDETERMINED_FACET,
            signature: $signature,
            facet: $facet->value,
            documented: null,
            observed: 'undeterminable',
            provenanceRank: SurfaceFindingData::RANK_OBSERVED,
            location: $posture->name,
        );
    }

    private function describe(?bool $value): string
    {
        return match ($value) {
            true => 'required',
            false => 'open',
            null => 'undeterminable',
        };
    }

    /**
     * @param  list<SurfaceFindingData>  $findings
     * @return list<SurfaceFindingData>
     */
    private function sorted(array $findings): array
    {
        usort($findings, fn (SurfaceFindingData $a, SurfaceFindingData $b) => [$a->signature, $a->kind, $a->facet]
            <=> [$b->signature, $b->kind, $b->facet]);

        return array_values($findings);
    }
}
