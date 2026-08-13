<?php

namespace Splicewire\Beam\Revisions;

use Splicewire\Beam\Activity\ActivityEntry;

/**
 * A typed projection of one activity-log row whose `old` image is a RESTORABLE attribute pre-image
 * — the substrate-level revision record. Generalized up from composition's app-local
 * `RevisionEntry` (ADR-0049 §2) so *every* beam BeamParticle — generation-, edit-, or
 * submission-populated — gets change-history + undo/redo, not just composition cells.
 *
 * The generic entry shape (id/subject/causer/old/new/cause/correlation/timestamp) now lives in the
 * {@see ActivityEntry} base, mirroring the {@see RevisionRecorder} / ActivityRecorder split: this
 * subclass adds no fields — it is the type {@see RevisionRecorder::revert()} accepts, naming the
 * CONTRACT that its pre-image can be forceFilled back onto the subject. An entry that merely
 * narrates (e.g. beam-rank's history) surfaces as the plain {@see ActivityEntry}.
 */
class RevisionEntry extends ActivityEntry {}
