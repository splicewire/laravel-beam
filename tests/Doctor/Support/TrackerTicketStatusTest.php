<?php

namespace Splicewire\Beam\Tests\Doctor\Support;

use Splicewire\Beam\Doctor\Support\TrackerTicketStatus;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel ticket 35 §2 — the tracker seam behind the third staleness finding.
 *
 * The case that matters most is the third answer: `null` for unanswerable. Everything else here is a
 * two-line file read, but "no tracker" resolving to open or to closed are both silent lies at fleet scale,
 * and neither would ever be noticed by a test that only checked the happy pair.
 */
class TrackerTicketStatusTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tracker-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/effort/tickets', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/effort/tickets/*.md') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->root.'/effort/tickets');
        @rmdir($this->root.'/effort');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function ticket(string $name, string $status): string
    {
        $relative = 'effort/tickets/'.$name.'.md';
        file_put_contents($this->root.'/'.$relative, "# {$name}\n\n**Status:** {$status} · **Assignee:** someone\n");

        return $relative;
    }

    public function test_an_open_ticket_reads_open(): void
    {
        $status = new TrackerTicketStatus($this->root);

        $this->assertTrue($status($this->ticket('07-live', 'open')));
    }

    public function test_a_closed_ticket_reads_closed(): void
    {
        $status = new TrackerTicketStatus($this->root);

        $this->assertFalse($status($this->ticket('07-done', 'closed')));
    }

    public function test_no_configured_root_is_unanswerable(): void
    {
        $status = new TrackerTicketStatus(null);

        $this->assertNull($status('effort/tickets/07-live.md'));
    }

    public function test_a_missing_file_is_unanswerable(): void
    {
        $status = new TrackerTicketStatus($this->root);

        $this->assertNull($status('effort/tickets/nothing-here.md'));
    }

    public function test_a_file_with_no_status_line_is_unanswerable(): void
    {
        file_put_contents($this->root.'/effort/tickets/07-bare.md', "# 07\n\nNo status here.\n");
        $status = new TrackerTicketStatus($this->root);

        // Not "open by default": a ticket whose file exists but says nothing is a tracker convention this
        // host does not understand, which is a different fact from a live blocker.
        $this->assertNull($status('effort/tickets/07-bare.md'));
    }

    public function test_a_traversing_reference_is_refused_rather_than_followed(): void
    {
        $status = new TrackerTicketStatus($this->root);

        // The artifact these references come from is exactly the kind of file that gets hand-edited.
        $this->assertNull($status('../../../etc/hosts'));
        $this->assertNull($status('/etc/hosts'));
    }
}
