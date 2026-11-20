<?php

namespace Splicewire\Beam\Testing;

/**
 * The screenshot handle a {@see flow()} call returns: the committed-baseline basename for this flow, role
 * and width (browser-journeys 10). A shipped browser test passes `->name()` to pest-plugin-browser's
 * `screenshot(filename:)`. The helper only NAMES the capture; writing a committed baseline under
 * `tests/Browser/screenshots` is ticket 17's record command, and an ordinary run's capture lands in the
 * gitignored `tests/Browser/Screenshots` (capital S).
 */
final readonly class FlowScreenshot
{
    public function __construct(public string $name) {}

    /** The screenshot basename, `<flow key>[--<role>]@<width>` (no extension). */
    public function name(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
