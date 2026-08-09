<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures;

class NoHasMigrationsProvider
{
    /**
     * Ships a config file only — no migrations wired at all. Deliberately NAMES loadMigrationsFrom()
     * in this docblock without calling it, to exercise the audit's comment-vs-call distinction.
     */
    public function configurePackage(): void
    {
        //
    }
}
