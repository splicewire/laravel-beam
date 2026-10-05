<?php

namespace Splicewire\Beam\Testing;

use Splicewire\Beam\Schema\IdentifierLabels;

/**
 * A host's `schema.identifier-label` RATCHET (app-walkthrough APP-09): every class its doctor would name is listed
 * with its owner. An unlisted one fails (a new class reachable from a particle with no declared label), and so does a
 * stale entry (the class now declares one, or is no longer reachable), so the list only shrinks.
 */
trait AssertsIdentifierLabels
{
    /**
     * Class => owner: the ticket that labels it.
     *
     * @return array<class-string, string>
     */
    abstract protected function identifierLabelRatchet(): array;

    protected function assertIdentifierLabelRatchet(): void
    {
        $found = app(IdentifierLabels::class)->find();
        $ratchet = $this->identifierLabelRatchet();
        $unlisted = array_diff_key($found, $ratchet);
        $stale = array_diff_key($ratchet, $found);

        $lines = [];
        foreach ($ratchet as $class => $owner) {
            $lines[] = (isset($found[$class]) ? '  known  ' : '  STALE  ')."{$class}  →  {$owner}";
        }
        foreach ($unlisted as $class => $unit) {
            $lines[] = "  NEW    {$class}  ({$unit})";
        }
        fwrite(STDERR, "\nIdentifier-label ratchet at ".basename(base_path()).': '.count($found).' found, '.count($ratchet).' listed, '
            .count($unlisted).' unlisted, '.count($stale)." stale\n".implode("\n", $lines)."\n");

        $this->assertSame([], array_keys($unlisted), 'Unlisted classes render an identifier: declare #[Title] (or ProvidesEnumLabel), or list each with its owner.');
        $this->assertSame([], array_keys($stale), 'Stale entries: the class declares its label or is unreachable, so delete the entry.');
    }
}
