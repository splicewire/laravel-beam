<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\CollectionBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 02 — ADR-0230 (0230-…md:68-69): "a signed-in reader whose own read is refused still receives what the Public
 * Read gives a guest. Anything less would show a visitor less after signing in." Over REST, index and show, for a reader
 * whose policy refuses AND for one with no policy at all. Before this, REST asked only the signed-in reader's own guard: a
 * refused reader got 403 where a guest got the Public Read (the tutorial's ch-01 measured it with CalculatorPolicy deleted).
 */
class SignedInPublicReadFallbackTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('f', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', FallbackReader::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('fallback_readers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        Gadget::create(['user_id' => 1]); // public: the Public Read's scope admits it
        Gadget::create(['user_id' => 2]); // private

        Route::get('gadgets', [ParticleController::class, 'index'])->defaults(ParticleController::RESOURCE, 'gadgets');
        Route::get('gadgets/{id}', [ParticleController::class, 'show'])->defaults(ParticleController::RESOURCE, 'gadgets');
    }

    /** The resource's own read projects `user_id`; its Public Read ({@see GadgetData}) is narrower: the id alone. */
    private function declare(bool $publicRead = true): ParticleResource
    {
        $resource = new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: FullGadgetData::class,
            publicRead: $publicRead ? GadgetData::class : null,
            project: fn (Gadget $gadget): FullGadgetData => new FullGadgetData((string) $gadget->getKey(), (int) $gadget->user_id),
            readOnly: true,
            label: 'Gadgets',
        );
        $resource->publicScope = fn ($query) => $query->where('user_id', 1);
        app(ParticleResourceRegistry::class)->register($resource);

        return $resource;
    }

    private function signIn(): void
    {
        $this->actingAs(FallbackReader::create(['name' => 'Ada']));
    }

    public function test_a_signed_in_reader_the_policy_refuses_reads_a_public_record_through_the_public_read(): void
    {
        $this->declare();
        Gate::define('view', fn (?User $actor, Gadget $record): bool => false);
        Gate::define('viewAny', fn (?User $actor): bool => false);
        $this->signIn();

        $this->getJson('/gadgets/1')->assertOk()->assertJsonPath('data.id', '1')->assertJsonMissingPath('data.user_id');
        $this->getJson('/gadgets/2')->assertNotFound();
    }

    public function test_a_signed_in_reader_with_no_policy_at_all_reads_through_the_public_read(): void
    {
        $this->declare();
        $this->signIn();

        $this->getJson('/gadgets/1')->assertOk()->assertJsonPath('data.id', '1')->assertJsonMissingPath('data.user_id');
        $this->getJson('/gadgets/2')->assertNotFound();
    }

    public function test_the_refused_or_policy_less_reader_lists_exactly_the_public_read(): void
    {
        $this->declare();
        $this->signIn();

        $rows = $this->getJson('/gadgets')->assertOk()->json('data');

        $this->assertSame(['1'], array_column($rows, 'id'), 'only the Public Read scope');
        $this->assertArrayNotHasKey('user_id', $rows[0], 'only the Public Read fields');
    }

    public function test_a_reader_the_policy_admits_keeps_their_own_read(): void
    {
        $this->declare();
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        Gate::define('viewAny', fn (?User $actor): bool => true);
        $this->signIn();

        $this->getJson('/gadgets/2')->assertOk()->assertJsonPath('data.user_id', 2);
        $this->assertCount(2, $this->getJson('/gadgets')->assertOk()->json('data'));
    }

    /**
     * orch-peer's REVISE: a streams-only backing has no query to narrow. streamedIndex() hands it the raw facets and serves
     * its rows (pre-projected Data included) as-is, so neither the Public Read's scope, nor its filter/sort allowlist, nor
     * its projection can be applied. A floor-bound reader is refused there, as before tutorial-dx 02, unless the floor IS
     * the whole read: the Public Read is the resource's own Data and no public scope narrows it.
     */
    public function test_a_floor_bound_reader_is_refused_a_streams_only_index_the_floor_cannot_be_applied_to(): void
    {
        Route::get('streams', [ParticleController::class, 'index'])->defaults(ParticleController::RESOURCE, 'streams');
        $resource = new ParticleResource(key: 'streams', backing: PrivateRowStream::class, data: FullGadgetData::class, publicRead: GadgetData::class, readOnly: true, label: 'Streams');
        $resource->publicScope = fn ($query) => $query; // declared, and impossible to apply to a stream
        app(ParticleResourceRegistry::class)->register($resource);
        $this->signIn();

        $this->getJson('/streams')->assertForbidden();
        $this->getJson('/streams?filter[user_id]=2')->assertForbidden();
        $this->assertSame([], PrivateRowStream::$filtersSeen, 'the backing was never asked, with or without a hidden-field filter');
    }

    public function test_a_floor_bound_reader_reads_a_streams_only_index_whose_floor_is_the_whole_read(): void
    {
        // calcucrypt's `plans` shape (PlanCatalog extends CollectionBacking): publicRead is the resource's own Data, no publicScope.
        Route::get('catalog', [ParticleController::class, 'index'])->defaults(ParticleController::RESOURCE, 'catalog');
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'catalog', backing: OpenRowStream::class, data: GadgetData::class, publicRead: GadgetData::class, readOnly: true, label: 'Catalog'));
        $this->signIn();

        $ids = array_column($this->getJson('/catalog')->assertOk()->json('data'), 'id');
        sort($ids); // the backing pages in its own order
        $this->assertSame(['1', '2'], $ids);
    }

    public function test_without_a_public_read_the_refused_reader_stays_refused(): void
    {
        $this->declare(publicRead: false);
        Gate::define('view', fn (?User $actor, Gadget $record): bool => false);
        Gate::define('viewAny', fn (?User $actor): bool => false);
        $this->signIn();

        $this->getJson('/gadgets/1')->assertForbidden();
        $this->getJson('/gadgets')->assertForbidden();
    }
}

class FallbackReader extends User
{
    protected $table = 'fallback_readers';

    protected $guarded = [];

    public $timestamps = false;
}

/** A streams-only backing whose rows are pre-projected and carry a private field (user_id), public and private alike. */
class PrivateRowStream extends CollectionBacking
{
    /** @var array<int, array<string, mixed>> */
    public static array $filtersSeen = [];

    protected function rows(array $filters): iterable
    {
        self::$filtersSeen[] = $filters;

        return [new FullGadgetData('1', 1), new FullGadgetData('2', 2)];
    }
}

/** A streams-only backing whose every field is public (the Public Read is the resource's own Data). */
class OpenRowStream extends CollectionBacking
{
    protected function rows(array $filters): iterable
    {
        return [new GadgetData('1'), new GadgetData('2')];
    }
}

class FullGadgetData extends Data
{
    public function __construct(
        public string $id,
        public int $user_id,
    ) {}
}
