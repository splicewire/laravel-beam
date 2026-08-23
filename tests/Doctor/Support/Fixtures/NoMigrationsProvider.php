<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures;

class NoMigrationsProvider
{
    /**
     * Ships a config file only — no `->hasMigrations([...])` AND no migration files on disk, so the
     * publish-only convention has no subject and the audit passes (beam-facade ticket 77). Contrast
     * {@see UnregisteredStubs\Src\UnregisteredStubsProvider}, which has the files and not the
     * registration. Deliberately NAMES loadMigrationsFrom() in this docblock without calling it, to
     * exercise the audit's comment-vs-call distinction.
     */
    public function configurePackage(): void
    {
        //
    }
}
