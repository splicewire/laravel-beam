<?php

namespace Splicewire\Beam\Tests\Data;

use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Tests\TestCase;

class RejectsUnknownInputKeysTest extends TestCase
{
    public function test_declared_keys_are_accepted_and_unknown_keys_are_refused_by_name(): void
    {
        $this->assertSame('kept', StrictInputData::from(['wireName' => 'kept'])->wireName);

        try {
            StrictInputData::from(['wireName' => 'kept', 'wire_name' => 'refused']);
            $this->fail('An undeclared input key should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Unknown input field [wire_name].'],
                $exception->errors()['wire_name'],
            );
        }
    }

    public function test_the_declared_key_set_is_independent_of_the_host_input_mapper(): void
    {
        config()->set('data.name_mapping_strategy.input', CamelCaseMapper::class);
        app(DataConfig::class)->reset();

        $this->expectException(ValidationException::class);

        StrictInputData::from(['wire_name' => 'refused']);
    }
}

class StrictInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[MapName('wireName')]
        public string $wireName,
    ) {}
}
