<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures;

class HostSharedMigrationsProvider
{
    public function configurePackage(): void
    {
        // ->hasMigrations(['shared/create_foo_table']);
    }

    public function packageBooted(): void
    {
        // HOST-side glue over an ALREADY-published directory — the sanctioned exception. Assigned to a
        // variable first (the realistic shape), not inlined, to exercise the assignment-tracing path.
        $sharedDir = database_path('migrations/shared');

        $this->loadMigrationsFrom($sharedDir);
    }
}
