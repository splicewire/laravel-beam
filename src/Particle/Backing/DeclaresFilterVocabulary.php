<?php

namespace Splicewire\Beam\Particle\Backing;

/**
 * The filters and sorts interpreted by a backing, including one that streams without an Eloquent query.
 * Frame projects this vocabulary into controls and validates saved views against the same names.
 * The backing implements execution; it must honor the facets it declares.
 *
 * Resolved per request, so the vocabulary may depend on injected state. A competing data-filters query
 * registration is invalid: no placeholder query or model is needed for metadata or saved views.
 */
interface DeclaresFilterVocabulary extends ResourceBacking
{
    public function filterVocabulary(): FilterVocabulary;
}
