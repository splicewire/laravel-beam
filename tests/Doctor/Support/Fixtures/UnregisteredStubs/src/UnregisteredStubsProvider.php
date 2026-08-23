<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures\UnregisteredStubs\Src;

class UnregisteredStubsProvider
{
    /**
     * The real defect the missing-`->hasMigrations([...])` warn is about: stub files that ship in the
     * package and that no registration will ever publish. One directory deep (`shared/`), so this also
     * covers the nested layout the estate's ubiquitous-table convention uses.
     */
    public function configurePackage(): void
    {
        //
    }
}
