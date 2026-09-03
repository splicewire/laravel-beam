<?php

namespace Splicewire\Beam\Doctor\Support;

use Splicewire\Beam\Doctor\MarketingSampleAudit;

/**
 * A contributor of marketing copy that is NOT a file on disk (competitive-landscape ticket 06).
 *
 * {@see MarketingSampleAudit} reads two kinds of source, because the estate's own marketing copy lives in
 * two kinds of place and only one of them is a file:
 *
 *  - **Island source** — `resources/js/components/marketing/*.tsx`. A path glob reaches it.
 *  - **Prose** — a ~49 KB block-tree payload inside a `beam_particles` row. The disk file beside it
 *    (`resources/js/content/page/beam.tsx`) is an OUTBOUND PROJECTION written on Publish; the particle is
 *    source-of-record, so a scanner that reads only the filesystem can pass while the served page is
 *    wrong. Ticket 06 measured that split rather than assuming it: `route macros` was PRESENT in the
 *    particle payload and absent from every island.
 *
 * A host implements this to hand the audit the second kind — whatever query, disk, or API read produces
 * the text it actually serves. The contract is deliberately plain text keyed by a label a human can act
 * on: the audit does not care whether the text arrived as JSON, MDX, or a block tree, only that what it
 * scans is what the visitor reads.
 */
interface MarketingCopySource
{
    /**
     * @return array<string, string> label (shown in the finding) => the copy's plain text
     */
    public function documents(): array;
}
