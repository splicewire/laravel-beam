<?php

namespace Splicewire\Beam\Data;

/**
 * The metadata emitted by a successful ResponseBody. Concrete response declarations add a typed
 * data property; this base deliberately declares no payload and is not a runtime response wrapper.
 */
abstract class SuccessResponseData extends BeamData
{
    public bool $success = true;

    public ?string $message = null;

    public ?int $limit = null;

    public ?int $offset = null;

    public ?int $total = null;
}
