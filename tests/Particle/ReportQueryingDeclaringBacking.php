<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Tests\Doctor\ParticleCapabilityDisagreementAuditTest;

/**
 * Its own file so PSR-4 autoloads it: {@see ParticleCapabilityDisagreementAuditTest} imports it
 * too, and declared inside ResourceRegistryReportTest it existed only when that file had loaded first. Alone, the
 * audit saw a class that does not exist and passed.
 */
class ReportQueryingDeclaringBacking implements DeclaresFilterVocabulary, QueriesRecords, StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }

    public function query(array $filters): Builder
    {
        throw new \LogicException('The report never queries a backing.');
    }

    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::search('keywords'));
    }
}
