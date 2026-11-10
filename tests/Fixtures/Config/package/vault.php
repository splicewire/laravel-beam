<?php

// Fixture: a package root whose `connection` map holds a declared secret (nested-secret redaction regression).
return [
    'connection' => [
        'host' => 'db.internal',
        'password' => 'FAKE-NESTED-SECRET-7d3',
    ],
    'driver' => 'pgsql',
];
