<?php

// Fixture: the host's copy of `panels`. It turns panels OFF, so an operator override that turns them on, then an
// `unset`, proves the key returns to the HOST value and not the package's.
return [
    'enabled' => false,
];
