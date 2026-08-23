<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures\RealPhp\Src;

use Splicewire\Beam\Tests\Doctor\Support\Fixtures\CleanStub\Src\CleanStubProvider;

class RealPhpProvider
{
    /**
     * Registers properly and still ships a real (non-`.stub`) `.php` migration beside the stubs — the
     * fail case. The registration has to be a real call for the audit to reach that check at all: with
     * comments stripped (beam-facade 77), the commented-out version this fixture used to carry stops
     * short at the missing-registration warn.
     *
     * The `Package` shim is duplicated from {@see CleanStubProvider}
     * on purpose — see the note there: the audit reads only the provider's own file, so a call site in
     * a shared trait is invisible to it.
     */
    public function configurePackage(): void
    {
        $this->package()->hasMigrations(['create_bar_table']);
    }

    /** Stands in for spatie/laravel-package-tools' `Package`; never runs, only has to be code. */
    private function package(): object
    {
        return new class
        {
            /** @param list<string> $migrations */
            public function hasMigrations(array $migrations): self
            {
                return $this;
            }
        };
    }
}
