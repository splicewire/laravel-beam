<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures\CleanStub\Src;

use Splicewire\Beam\Tests\Doctor\Support\Fixtures\RealPhp\Src\RealPhpProvider;

class CleanStubProvider
{
    /**
     * The clean case: a real registration plus a `.php.stub` beside it.
     *
     * The call used to sit here COMMENTED OUT and the audit passed on it anyway — the false-pass
     * beam-facade 77 closed by stripping comments before running the predicates.
     *
     * The `Package` shim below is duplicated in {@see RealPhpProvider}
     * rather than shared through a trait, and it has to be: the audit reads ONE file — the provider's
     * own source — so a call site hoisted into a trait is a call site the audit cannot see. That is the
     * same per-file blindness the fixture exists to pin.
     */
    public function configurePackage(): void
    {
        $this->package()->hasMigrations(['create_foo_table']);
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
