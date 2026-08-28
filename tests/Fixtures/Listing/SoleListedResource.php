<?php

namespace Splicewire\Beam\Tests\Fixtures\Listing;

use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Surgeon\ListedResourceDisplacementAudit;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperModel;

/**
 * A listed class on a key nothing else ever registers — the live counter-example at `~/Herd/splicewire`
 * (`WorkflowAwaitingRowData`, attributed in a package the scan does not reach), which is why
 * {@see ListedResourceDisplacementAudit} cannot simply report "you listed
 * something already attributed". Here the listing IS the registration, and the audit must stay silent.
 */
#[ParticleResource(
    key: 'sole-listed-papers',
    backing: ScannedPaperModel::class,
)]
class SoleListedResource {}
