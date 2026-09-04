<?php

namespace Splicewire\Beam\Surgeon\Support;

/**
 * What one {@see EloquentModelSource::scan()} saw — deliberately a value object rather than a bare
 * array, because the third field is the one a caller will otherwise forget to read.
 *
 * `models` alone answers *"which models are there"*. It cannot answer *"did I see all of them"*, and
 * a caller that reports a clean run off an empty `models` while `unnamed` is non-empty has produced
 * this estate's signature defect: an instrument that reports success by not running. Both counters
 * ride along so the honest reading is the cheap one.
 */
class Scan
{
    /**
     * @param  array<class-string, string>  $models  model class => the file that declares it
     * @param  list<string>  $unnamed  files whose text declares a model but whose tokens name no class
     * @param  int  $filesScanned  every `.php` file walked, model or not — `0` means the population was
     *                             empty or unreachable, which is "did not look" and never "clean"
     */
    public function __construct(
        public array $models,
        public array $unnamed,
        public int $filesScanned,
    ) {}
}
