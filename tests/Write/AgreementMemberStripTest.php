<?php

namespace Splicewire\Beam\Tests\Write;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;
use Splicewire\Beam\Write\ModelAttributeMapper;

/**
 * agreements 05 amend (review-r1 P2 follow-through): a resource input may declare an agreement member, which
 * requirements admission reads. It is admission input, never a column, so the one column mapper both transports use
 * ({@see ModelAttributeMapper}) drops it before the write fills the model. Without that, a `$guarded = []` model
 * tried to insert `agreement_member` and the create answered 500.
 */
class AgreementMemberStripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('member_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('note')->nullable();
        });
        Gate::policy(MemberRow::class, MemberRowPolicy::class);
    }

    public function test_rest_create_and_update_with_a_member_write_only_the_columns(): void
    {
        $this->resource();
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Ada']));
        $member = ['agreementMember' => ['key' => 'terms', 'version' => 1, 'digest' => str_repeat('a', 64)]];

        $this->postJson('/member-rows', ['name' => 'a'] + $member)->assertSuccessful();
        $this->putJson('/member-rows/1', ['name' => 'b'] + $member)->assertSuccessful();

        $row = MemberRow::query()->sole();
        $this->assertSame('b', $row->name);
        $this->assertArrayNotHasKey('agreement_member', $row->getAttributes());
        $this->assertArrayNotHasKey('agreementMember', $row->getAttributes());
    }

    public function test_frame_create_and_update_with_a_member_write_only_the_columns(): void
    {
        $definition = $this->resource(mount: false)->toResourceDefinition();
        $actor = new GenericUser(['id' => 1, 'name' => 'Ada']);
        $this->actingAs($actor);
        $handler = $this->app->make(ParticleFrameResourceHandler::class)->forActor($actor);
        $member = ['agreementMember' => ['key' => 'terms', 'version' => 1, 'digest' => str_repeat('a', 64)]];

        $this->assertSame('a', $handler->store($definition, ['name' => 'a'] + $member)['name']);
        $this->assertSame('b', $handler->update($definition, '1', ['name' => 'b'] + $member)['name']);

        $this->assertArrayNotHasKey('agreement_member', MemberRow::query()->sole()->getAttributes());
    }

    public function test_the_mapper_drops_a_declared_member_by_name_snake_form_and_mapped_input_name(): void
    {
        $member = new AgreementMemberData('terms', 1, str_repeat('a', 64));

        $this->assertSame(['name' => 'a'], ModelAttributeMapper::map(new MemberRowInput('a', $member)));
        $this->assertSame(['name' => 'a'], ModelAttributeMapper::map(new MappedMemberInput('a', $member)));
        // An input with no member is mapped exactly as before.
        $this->assertSame(['name' => 'a'], ModelAttributeMapper::map(new PlainRowInput('a')));
    }

    /** review-r1 amend check: an input's own column map (both branches) must not pass a member through either. */
    public function test_rest_writes_through_a_declared_map_drop_a_member_it_passes_through(): void
    {
        $this->assertRestWriteDropsTheMember(DeclaredMapInput::class);
    }

    public function test_rest_writes_through_a_duck_typed_map_drop_a_member_it_passes_through(): void
    {
        $this->assertRestWriteDropsTheMember(DuckMapInput::class);
    }

    public function test_frame_writes_through_a_declared_map_drop_a_member_it_passes_through(): void
    {
        $this->assertFrameWriteDropsTheMember(DeclaredMapInput::class);
    }

    public function test_frame_writes_through_a_duck_typed_map_drop_a_member_it_passes_through(): void
    {
        $this->assertFrameWriteDropsTheMember(DuckMapInput::class);
    }

    public function test_the_mapper_filters_a_member_out_of_both_mapped_branches_and_keeps_every_other_column(): void
    {
        $member = new AgreementMemberData('terms', 1, str_repeat('a', 64));

        $this->assertSame(['name' => 'a', 'note' => 'kept'], ModelAttributeMapper::map(new DeclaredMapInput('a', $member)));
        $this->assertSame(['name' => 'a', 'note' => 'kept'], ModelAttributeMapper::map(new DuckMapInput('a', $member)));
    }

    private function assertRestWriteDropsTheMember(string $input): void
    {
        $this->resource(input: $input);
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Ada']));
        $member = ['agreementMember' => ['key' => 'terms', 'version' => 1, 'digest' => str_repeat('a', 64)]];

        $this->postJson('/member-rows', ['name' => 'a'] + $member)->assertSuccessful();
        $this->putJson('/member-rows/'.MemberRow::query()->sole()->getKey(), ['name' => 'b'] + $member)->assertSuccessful();

        $row = MemberRow::query()->sole();
        $this->assertSame('b', $row->name, 'the mapped column is written');
        $this->assertSame('kept', $row->note, 'every other mapped column is unchanged');
        $this->assertArrayNotHasKey('agreement_member', $row->getAttributes());
    }

    private function assertFrameWriteDropsTheMember(string $input): void
    {
        $definition = $this->resource(mount: false, input: $input)->toResourceDefinition();
        $actor = new GenericUser(['id' => 1, 'name' => 'Ada']);
        $this->actingAs($actor);
        $handler = $this->app->make(ParticleFrameResourceHandler::class)->forActor($actor);
        $member = ['agreementMember' => ['key' => 'terms', 'version' => 1, 'digest' => str_repeat('a', 64)]];

        $handler->store($definition, ['name' => 'a'] + $member);
        $this->assertSame('b', $handler->update($definition, (string) MemberRow::query()->sole()->getKey(), ['name' => 'b'] + $member)['name']);

        $row = MemberRow::query()->sole();
        $this->assertSame('kept', $row->note);
        $this->assertArrayNotHasKey('agreement_member', $row->getAttributes());
    }

    private function resource(bool $mount = true, string $input = MemberRowInput::class): ParticleResource
    {
        $resource = new ParticleResource(
            key: 'member-rows',
            backing: MemberRow::class,
            data: MemberRowData::class,
            input: $input,
            project: fn (MemberRow $row): MemberRowData => new MemberRowData($row->getKey(), $row->name),
            label: 'Member rows',
            scope: fn ($query) => $query->whereNotNull('id'),
        );
        $this->app->make(ParticleResourceRegistry::class)->register($resource);

        if ($mount) {
            Particle::mount('member-rows', 'member-rows')->only(['store', 'update']);
        }

        return $resource;
    }
}

class MemberRow extends Model
{
    protected $table = 'member_rows';

    // Everything is fillable, so a leaked member would reach the INSERT.
    protected $guarded = [];

    public $timestamps = false;
}

class MemberRowData extends Data
{
    public function __construct(public int $id, public ?string $name = null) {}
}

class MemberRowInput extends Data
{
    public function __construct(public ?string $name = null, public ?AgreementMemberData $agreementMember = null) {}
}

class MappedMemberInput extends Data
{
    public function __construct(
        public ?string $name = null,
        #[MapInputName('agreement_member')] public ?AgreementMemberData $member = null,
    ) {}
}

/** An input with a DECLARED column map that (carelessly) passes the member through beside a real column. */
class DeclaredMapInput extends Data implements MapsToModelAttributes
{
    public function __construct(public ?string $name = null, public ?AgreementMemberData $agreementMember = null) {}

    public function toModelAttributes(): array
    {
        return ['name' => $this->name, 'agreement_member' => $this->agreementMember, 'note' => 'kept'];
    }
}

/** The same through the duck-typed map. */
class DuckMapInput extends Data
{
    public function __construct(public ?string $name = null, public ?AgreementMemberData $agreementMember = null) {}

    public function toModelAttributes(): array
    {
        return ['name' => $this->name, 'agreement_member' => $this->agreementMember, 'note' => 'kept'];
    }
}

class PlainRowInput extends Data
{
    public function __construct(public ?string $name = null) {}
}

class MemberRowPolicy
{
    public function create(?Authenticatable $user): bool
    {
        return true;
    }

    public function update(?Authenticatable $user, Model $row): bool
    {
        return true;
    }

    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, Model $row): bool
    {
        return true;
    }
}
