<?php

namespace Splicewire\Beam\Doctor\Support;

/**
 * The verdict {@see BrowserCoverageReader} returns: one row per EXPECTED installed browser suite, each
 * marked `proved` / `failed` / `skipped` / `not-collected`, plus the overall pass this gates on.
 *
 * The load-bearing rule lives in {@see ok()}: a `not-collected` suite (zero testcases attributed to it in
 * the JUnit) is NEVER a pass. A run that never collected a mounted package's suite looks identical to a
 * clean one in its summary line — same green, same exit 0 — which is exactly the silent success
 * browser-journeys 13 refuses. So the reader turns absence into a failing verdict the Sweep reads as a
 * non-zero exit, independent of why the suite was absent (ticket 03 Q6).
 */
final class BrowserCoverageReport
{
    public const PROVED = 'proved';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const NOT_COLLECTED = 'not-collected';

    /**
     * @param  list<array{package: string, status: string, testcases: int, detail: string}>  $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * The gate: true only when every expected suite was collected and none failed. A `not-collected` or a
     * `failed` row makes it false — the Sweep exits non-zero. A `skipped` row (the whole suite skipped with a
     * recorded reason, e.g. a deviation) is a pass: it WAS collected and honestly declared off.
     */
    public function ok(): bool
    {
        foreach ($this->items as $item) {
            if ($item['status'] === self::NOT_COLLECTED || $item['status'] === self::FAILED) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{package: string, status: string, testcases: int, detail: string}> */
    public function notCollected(): array
    {
        return array_values(array_filter($this->items, static fn (array $i): bool => $i['status'] === self::NOT_COLLECTED));
    }

    /** @return list<array{package: string, status: string, testcases: int, detail: string}> */
    public function failing(): array
    {
        return array_values(array_filter($this->items, static fn (array $i): bool => $i['status'] === self::FAILED));
    }

    /** One line per suite for a command/log: `<package>: <status> (<detail>)`. */
    public function lines(): array
    {
        return array_map(
            static fn (array $i): string => sprintf('%s: %s — %s', $i['package'], $i['status'], $i['detail']),
            $this->items,
        );
    }
}
