<?php

namespace Splicewire\Beam\Data\Concerns;

use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Support\DataConfig;

/** Refuse undeclared top-level DTO input keys instead of silently ignoring them. */
trait RejectsUnknownInputKeys
{
    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $declared = app(DataConfig::class)->getDataClass(static::class)->properties
            ->mapWithKeys(fn ($property): array => [($property->inputMappedName ?? $property->name) => true])
            ->all();
        $unknown = array_diff_key($properties, $declared);

        if ($unknown !== []) {
            $messages = [];
            foreach (array_keys($unknown) as $key) {
                $messages[(string) $key] = "Unknown input field [{$key}].";
            }

            throw ValidationException::withMessages($messages);
        }

        return parent::prepareForPipeline($properties);
    }
}
