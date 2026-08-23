<?php

namespace Splicewire\Beam\Doctor\Support;

use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;

/**
 * Answers *"is this ticket still open?"* against the fleet's file-backed issue tracker — the seam
 * {@see UndeclaredRegistryShapeAudit}'s third staleness finding needs, and the only place in beam that knows
 * a tracker exists.
 *
 * ## Why this is a seam rather than an `if` inside the audit
 *
 * A `deferred` disposition is *"blocked on a named OPEN ticket"* (14 D10), and the deferral rotting into a
 * permanent exemption is the failure that phrase invites. Checking it means reading something outside the
 * repository the audit ships in — a tracker that is a directory of Markdown here, and could be an HTTP API
 * in the next host. Wiring that read into the audit would put a filesystem layout, a Markdown convention and
 * a `~/`-relative path inside a class whose whole subject is PHP classes.
 *
 * ## Unanswerable is a THIRD answer, not a default
 *
 * `null` — no configured root, no such file, no `Status:` line — is returned as itself, and the audit
 * reports the count of unanswerable deferrals rather than resolving them either way. Defaulting to "open"
 * makes every stale deferral invisible; defaulting to "closed" reports every deferral as stale on a host
 * that simply has no tracker checked out. Both are worse than saying so.
 *
 * ## The reference format
 *
 * A `deferred` row's `ticket` is a path RELATIVE to the configured tracker root, e.g.
 * `rushing/laravel-popcorn/registry-kernel/tickets/37-migrate-the-exemplars.md`. Relative because the root
 * is a per-machine absolute path and a committed artifact must not carry one; a full path because a bare
 * number is ambiguous across the fleet's several concurrent maps — this effort alone has two neighbours
 * numbering their own tickets from 01.
 */
class TrackerTicketStatus
{
    public const CLOSED = 'closed';

    public function __construct(
        protected ?string $root,
    ) {}

    /**
     * Read the configured tracker root. `null` when unset, which is the honest state on any host that has
     * not checked the tracker out — see the class docblock on why that is not defaulted away.
     */
    public static function fromConfig(): self
    {
        $root = config('beam.core.registry_conformance.tracker_path');

        return new self(is_string($root) && $root !== '' ? $root : null);
    }

    /** True open, false closed, null unanswerable. */
    public function __invoke(string $ticket): ?bool
    {
        if ($this->root === null || $ticket === '') {
            return null;
        }

        // A `..` in a committed artifact reaching out of the tracker root is a row nobody wrote on purpose.
        // Refused as unanswerable rather than followed: this class reads whatever path it is handed, and the
        // artifact it reads them from is exactly the kind of file that gets edited by hand.
        if (str_contains($ticket, '..') || str_starts_with($ticket, '/')) {
            return null;
        }

        $path = rtrim($this->root, '/').'/'.ltrim($ticket, '/');

        if (! is_file($path)) {
            return null;
        }

        if (preg_match('/^\*\*Status:\*\*\s*(\S+)/mi', (string) file_get_contents($path), $m) !== 1) {
            return null;
        }

        return strtolower(rtrim($m[1], '.,')) !== self::CLOSED;
    }
}
