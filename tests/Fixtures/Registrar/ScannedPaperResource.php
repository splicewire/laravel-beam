<?php

namespace Splicewire\Beam\Tests\Fixtures\Registrar;

use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The one annotated class in this directory, so a scan of `tests/Fixtures/Registrar` finds exactly it.
 *
 * Exists for `ParticleResourceRegistrarOrderingTest`, which is the estate's only assertion that a
 * registrar attached in the OWNER's `boot()` loses to a consumer provider's hand-registration under
 * `OnDuplicate::Supersede` alone (registry-kernel tickets 19 D3 / 53). `perPage` is the discriminator:
 * the consumer registers the same key with a different one.
 */
#[ParticleResource(
    key: 'scanned-papers',
    backing: ScannedPaperModel::class,
    perPage: 7,
)]
class ScannedPaperResource {}
