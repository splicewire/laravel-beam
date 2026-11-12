<?php

namespace Splicewire\Beam\Particle\Backing;

/** Input refusal and business-payload mapping are persistence capabilities. */
interface AcceptsPayloadWrites extends ResourceBacking
{
    /** @param array<string, mixed> $input */
    public static function assertInput(array $input): void;

    /** @return array<string, mixed> */
    public static function payload(mixed $input): array;
}
