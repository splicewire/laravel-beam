<?php

namespace Splicewire\Beam\Tests\Write;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;
use Splicewire\Beam\Write\ModelAttributeMapper;

/**
 * The ONE input→column mapper (particle-write-surface 03), which replaced four near-identical copies of
 * itself in two packages and two hosts.
 *
 * The load-bearing assertions here are the two that were spelled differently in the copies — a `Request`
 * and an array must produce the SAME columns, and a `Data` with no declared map must be snake-cased like
 * any other body rather than returned raw from `toArray()` — plus the three-state one, which is the
 * reason the declared map outranks the fallback at all.
 */
class ModelAttributeMapperTest extends TestCase
{
    public function test_a_declared_map_wins(): void
    {
        $this->assertSame(
            ['name' => 'declared'],
            ModelAttributeMapper::map(new MapperContractInput),
        );
    }

    public function test_the_duck_type_still_works(): void
    {
        // The migration fallback: 25 classes carried this method with no interface, and the branch stays
        // until UndeclaredWriteMapAudit reports zero.
        $this->assertSame(
            ['name' => 'ducked'],
            ModelAttributeMapper::map(new MapperDuckTypedInput),
        );
    }

    public function test_a_declared_map_can_express_all_three_states(): void
    {
        $absent = ModelAttributeMapper::map(MapperThreeStateInput::from([]));
        $cleared = ModelAttributeMapper::map(MapperThreeStateInput::from(['token' => null]));
        $set = ModelAttributeMapper::map(MapperThreeStateInput::from(['token' => 'abc']));

        $this->assertArrayNotHasKey('token', $absent, 'an absent field must leave the column untouched');
        $this->assertArrayHasKey('token', $cleared, 'a present-and-null field must CLEAR the column');
        $this->assertNull($cleared['token']);
        $this->assertSame('abc', $set['token']);
    }

    public function test_an_array_body_is_snake_cased_and_loses_its_primary_key(): void
    {
        $this->assertSame(
            ['display_name' => 'x', 'order_column' => 3],
            ModelAttributeMapper::map(['id' => 'uuid', 'displayName' => 'x', 'orderColumn' => 3]),
        );
    }

    public function test_a_request_maps_identically_to_the_same_body_as_an_array(): void
    {
        $body = ['id' => 'uuid', 'displayName' => 'x'];

        $this->assertSame(
            ModelAttributeMapper::map($body),
            ModelAttributeMapper::map(Request::create('/', 'POST', $body)),
            'the Request-typed copy was the odd one out of the four; collapsing them must not change what it maps',
        );
    }

    public function test_a_data_object_without_a_map_is_snake_cased_like_any_other_body(): void
    {
        // ⚠️ This is the ONE behaviour the collapse changed: ParticleController's copy returned
        // `$input->toArray()` verbatim here, so a camelCase property reached a snake_case column only by
        // luck, and the PK was not dropped. Both were controller-local divergences from the other three.
        $this->assertSame(
            ['display_name' => 'x'],
            ModelAttributeMapper::map(MapperPlainInput::from(['id' => 'uuid', 'displayName' => 'x'])),
        );
    }

    public function test_columns_is_idempotent_over_an_already_snake_cased_body(): void
    {
        $this->assertSame(
            ['display_name' => 'x'],
            ModelAttributeMapper::columns(['display_name' => 'x']),
        );
    }
}

class MapperContractInput implements MapsToModelAttributes
{
    public function toModelAttributes(): array
    {
        return ['name' => 'declared'];
    }
}

class MapperDuckTypedInput
{
    public function toModelAttributes(): array
    {
        return ['name' => 'ducked'];
    }
}

class MapperPlainInput extends Data
{
    public function __construct(
        public ?string $id = null,
        public ?string $displayName = null,
    ) {}
}

class MapperThreeStateInput extends Data implements MapsToModelAttributes
{
    public function __construct(
        public string|Optional|null $token,
    ) {}

    public function toModelAttributes(): array
    {
        $attributes = [];

        if (! $this->token instanceof Optional) {
            $attributes['token'] = $this->token;
        }

        return $attributes;
    }
}
