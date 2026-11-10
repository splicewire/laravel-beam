<?php

// Fixture: the host replaces the whole `connection` ancestor at the top level (mergeConfigFrom is shallow), so the
// fold emits `/connection` as one leaf whose package layer is the package's whole map, secret included.
return [
    'connection' => null,
];
