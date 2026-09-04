<?php

namespace Splicewire\Beam\Tests\Surgeon\Fixtures;

use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Tests\Surgeon\ModelSurfaceCoverageAuditTest;

/**
 * A backing that is a {@see ResourceBacking} and deliberately NOT
 * {@see BacksModel} — the `members` / `review-queue` shape, which
 * backs no single Eloquent model.
 *
 * It exists so {@see ModelSurfaceCoverageAuditTest} can prove the
 * coverage index subtracts MODELS rather than registered resources: a source-backed resource covers
 * nothing, and treating registration itself as coverage would silence every finding at a host whose
 * resources are source-backed.
 */
class SourceOnlyBacking implements ResourceBacking {}
