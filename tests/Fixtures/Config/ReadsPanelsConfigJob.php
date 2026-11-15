<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Fixture: a queued job that records what `config('panels.title')` holds when it runs (config-sections ticket 05). */
class ReadsPanelsConfigJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public static mixed $seen = null;

    public function handle(): void
    {
        self::$seen = config('panels.title');
    }
}
