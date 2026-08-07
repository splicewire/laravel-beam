<?php

namespace Splicewire\Beam\Doctor;

use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;

class BeamCoreMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam';
    }

    protected function serviceProviderClass(): string
    {
        return BeamServiceProvider::class;
    }
}
