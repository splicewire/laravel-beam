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

    /**
     * The capture filename, `<flow key>[--<role>]@<width>.png`.
     *
     * The `.png` extension is explicit: a flow key carries dots (e.g. `readings.offering`), and
     * pest-plugin-browser's `screenshot(filename:)` only appends `.png` when the filename has no
     * extension — it reads the text after the key's last dot as the extension and leaves the
     * capture without `.png`, so `record-baselines` (which globs `*.png`) finds no promotable
     * captures. Suffixing here makes a dotted key resolve to a real `.png` file.
     */
    public function name(): string
    {
        return str_ends_with($this->name, '.png') ? $this->name : $this->name.'.png';
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
