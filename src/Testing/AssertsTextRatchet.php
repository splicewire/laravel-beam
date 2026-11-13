<?php

namespace Splicewire\Beam\Testing;

use Splicewire\Beam\Localization\Ratchet\TextRatchet;

/**
 * A package's localization ratchet test (localization 12): the user-facing literals in its response-reaching calls may
 * only fall. A package's test calls {@see assertTextRatchet()} with its root. A file that ROSE fails (key the new text
 * instead); a file that FELL fails too, naming it, so the committed baseline is re-written and the gain is kept.
 * Compares only; `php artisan beam:lang:ratchet --write <root>` writes.
 */
trait AssertsTextRatchet
{
    protected function assertTextRatchet(string $packageRoot): void
    {
        $result = TextRatchet::compare($packageRoot);

        $this->assertFalse($result['missing'], 'No '.TextRatchet::FILE.' at '.$packageRoot.': write it with `php artisan beam:lang:ratchet --write '.$packageRoot.'`.');

        $describe = static fn (array $files): array => array_map(
            static fn (string $file, array $counts): string => "{$file}: {$counts[0]} → {$counts[1]}",
            array_keys($files),
            $files,
        );

        $this->assertSame([], $describe($result['rose']), 'User-facing literals ROSE: give the new text an explicit key in your namespace (docs/agents/user-facing-text.convention.md).');
        $this->assertSame([], $describe($result['fell']), 'User-facing literals FELL: keep the gain, re-write the baseline with `php artisan beam:lang:ratchet --write`.');
    }
}
