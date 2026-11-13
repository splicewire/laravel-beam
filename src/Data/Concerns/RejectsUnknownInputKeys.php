<?php

namespace Splicewire\Beam\Data\Concerns;

use Spatie\LaravelData\Support\DataConfig;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Particle\ParticleRequestError;

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
            // localization 21: a coded refusal per unknown field (the English unchanged), its code beside the message.
            $refusals = [];
            foreach (array_keys($unknown) as $key) {
                $refusals[(string) $key] = [ParticleRequestError::UnknownInputField, ['field' => (string) $key]];
            }

            throw CodedValidationException::many($refusals);
        }

        return parent::prepareForPipeline($properties);
    }
}
