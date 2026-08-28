<?php

namespace Splicewire\Beam\Tests\Fixtures\Listing;

use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Surgeon\ListedResourceDisplacementAudit;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperModel;

/**
 * A HOST's re-declaration of the `scanned-papers` key — the documented "same attribute, pointed at my
 * own model" escape hatch a host writes into `beam.core.resources.classes`.
 *
 * Deliberately NOT in `tests/Fixtures/Registrar`, so the discover-path scan never finds it: the only
 * thing that registers this class is the explicit config list, which is the intake
 * {@see ListedResourceDisplacementAudit} exists to measure.
 *
 * `perPage: 42` is the discriminator against the scanned declaration's 7 and the consumer provider's 99.
 */
#[ParticleResource(
    key: 'scanned-papers',
    backing: ScannedPaperModel::class,
    perPage: 42,
)]
class HostPaperResource {}
