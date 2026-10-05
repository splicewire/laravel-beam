<?php

namespace Splicewire\Beam\Testing;

use Splicewire\Beam\Http\UngatedWrites;

/**
 * A host's `http.ungated-write` RATCHET (app-walkthrough APP-08): every trio write route its doctor would name is
 * listed with its owner (the ticket that gates it, or why it is a public door by design). An unlisted one fails (a new
 * ungated write), and so does a stale entry (a route that is now gated or gone), so the list only shrinks.
 */
trait AssertsUngatedWrites
{
    /**
     * Route id (`METHODS uri`) => owner: the ticket that gates it, or "public door: why".
     *
     * @return array<string, string>
     */
    abstract protected function ungatedWriteRatchet(): array;

    protected function assertUngatedWriteRatchet(): void
    {
        $found = app(UngatedWrites::class)->find();
        $ratchet = $this->ungatedWriteRatchet();
        $unlisted = array_diff_key($found, $ratchet);
        $stale = array_diff_key($ratchet, $found);

        $lines = [];
        foreach ($ratchet as $id => $owner) {
            $lines[] = (isset($found[$id]) ? '  known  ' : '  STALE  ')."{$id}  →  {$owner}";
        }
        foreach ($unlisted as $id => $write) {
            $lines[] = '  NEW    '.$id.'  ('.($write['public'] ? 'PUBLIC, ' : '').$write['handler'].')';
        }
        fwrite(STDERR, "\nUngated-write ratchet at ".basename(base_path()).': '.count($found).' found, '.count($ratchet).' listed, '
            .count($unlisted).' unlisted, '.count($stale)." stale\n".implode("\n", $lines)."\n");

        $this->assertSame([], array_keys($unlisted), 'Unlisted ungated write routes: gate them, or list each with its owner.');
        $this->assertSame([], array_keys($stale), 'Stale entries: the route is gated or gone, so delete the entry.');
    }
}
