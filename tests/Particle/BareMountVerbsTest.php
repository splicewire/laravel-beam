<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * A bare `Particle::mount()` takes its verbs from the declaration; an explicit `only()` is the host's decision and wins.
 */
class BareMountVerbsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ledger_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('note')->nullable();
        });
    }

    /** @param  array<string, mixed>  $declared */
    private function declare(string $key, array $declared = []): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(...[
            'key' => $key,
            'backing' => LedgerRow::class,
            'data' => LedgerRowData::class,
            ...$declared,
        ]));
    }

    /** @return list<string> */
    private function mountedVerbs(string $key): array
    {
        $verbs = [];

        foreach ($this->app->make(Router::class)->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, "{$key}.")) {
                $verb = substr($name, strlen($key) + 1);

                if (in_array($verb, ['index', 'show', 'store', 'update', 'destroy'], true)) {
                    $verbs[] = $verb;
                }
            }
        }

        sort($verbs);

        return $verbs;
    }

    public function test_an_ordinary_resource_keeps_all_five_verbs(): void
    {
        $this->declare('open-rows');

        Particle::mount('open-rows')->register();

        $this->assertSame(['destroy', 'index', 'show', 'store', 'update'], $this->mountedVerbs('open-rows'));
    }

    public function test_a_read_only_resource_mounts_no_write_verb(): void
    {
        $this->declare('frozen-rows', ['input' => false, 'readOnly' => true]);

        Particle::mount('frozen-rows')->register();

        $this->assertSame(['index', 'show'], $this->mountedVerbs('frozen-rows'));
    }

    public function test_a_read_only_resource_that_is_not_showable_mounts_only_its_list(): void
    {
        $this->declare('listed-rows', ['readOnly' => true, 'showable' => false]);

        Particle::mount('listed-rows')->register();

        $this->assertSame(['index'], $this->mountedVerbs('listed-rows'));
    }

    public function test_the_declared_widenings_add_exactly_their_own_verb(): void
    {
        $this->declare('prunable-rows', ['readOnly' => true, 'deletable' => true]);
        $this->declare('editable-rows', ['readOnly' => true, 'editable' => true]);

        Particle::mount('prunable-rows')->register();
        Particle::mount('editable-rows')->register();

        $this->assertSame(['destroy', 'index', 'show'], $this->mountedVerbs('prunable-rows'));
        $this->assertSame(['index', 'show', 'update'], $this->mountedVerbs('editable-rows'));
    }

    public function test_an_explicit_only_is_the_hosts_decision_and_wins(): void
    {
        // The real shape: seller-repo-authorizations is read-only, accepts no body, and hosts mount its create.
        $this->declare('granted-rows', ['input' => false, 'readOnly' => true]);

        Particle::mount('granted-rows')->only(['index', 'store'])->register();

        $this->assertSame(['index', 'store'], $this->mountedVerbs('granted-rows'));
    }

    public function test_only_true_is_an_explicit_all_five_and_wins_over_a_read_only_declaration(): void
    {
        $this->declare('wildcard-rows', ['input' => false, 'readOnly' => true, 'showable' => false]);

        Particle::mount('wildcard-rows')->only(true)->register();

        $this->assertSame(['destroy', 'index', 'show', 'store', 'update'], $this->mountedVerbs('wildcard-rows'));
    }

    public function test_only_false_still_mounts_no_crud_verb(): void
    {
        $this->declare('ops-only-rows');

        Particle::mount('ops-only-rows')->only(false)->register();

        $this->assertSame([], $this->mountedVerbs('ops-only-rows'));
    }

    public function test_a_key_not_yet_registered_keeps_the_five_verbs(): void
    {
        Particle::mount('late-rows')->register();

        $this->assertSame(['destroy', 'index', 'show', 'store', 'update'], $this->mountedVerbs('late-rows'));
    }

    public function test_a_policy_that_allows_delete_cannot_delete_through_a_bare_read_only_mount(): void
    {
        $this->declare('frozen-rows', ['input' => false, 'readOnly' => true]);
        $row = LedgerRow::query()->create(['note' => 'kept']);

        Particle::mount('frozen-rows')->register();

        $this->actingAs(new BareMountUser);
        Gate::before(fn () => true);

        $this->deleteJson("/frozen-rows/{$row->getKey()}")->assertStatus(405);
        $this->assertSame(1, LedgerRow::query()->count());
    }
}

class LedgerRow extends Model
{
    protected $table = 'ledger_rows';

    public $timestamps = false;

    protected $guarded = [];
}

class LedgerRowData extends Data
{
    public function __construct(public ?string $note = null) {}
}

class BareMountUser extends User
{
    protected $guarded = [];
}
