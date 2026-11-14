<?php

namespace Splicewire\Beam\Localization\Presentation;

use LogicException;

/**
 * A product reference that cannot render: an unregistered key, a key no catalog answers, or parameters that don't
 * match the key's adapter schema (localization 37; RULING-04 §5: "Reference version/key validation fails tests for
 * missing product keys"). Thrown only under tests. Production renders the reference's own default plus a log
 * diagnostic instead.
 */
final class InvalidMessageReference extends LogicException {}
