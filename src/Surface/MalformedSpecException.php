<?php

namespace Splicewire\Beam\Surface;

use RuntimeException;

/**
 * A document handed to {@see SpecSource} is not a readable OpenAPI spec.
 *
 * Thrown rather than degraded to an empty inventory on purpose: an empty inventory reads downstream as
 * "this surface has no operations", which is indistinguishable from a clean audit of a tiny API. A
 * parse failure has to stay a failure all the way to the caller.
 */
class MalformedSpecException extends RuntimeException {}
