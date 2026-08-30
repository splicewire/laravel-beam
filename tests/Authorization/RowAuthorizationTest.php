<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Splicewire\Beam\Authorization\RowAuthorization;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel 72 §B — {@see RowAuthorization}, the row plane of authorization as one named idiom.
 *
 * ⚠️ **No `Gate::before(fn () => true)` appears in this file, and that is load-bearing rather than
 * stylistic.** The defect that produced this class survived for six days because the suite covering it
 * forced the gate open, so every read assertion in it measured nothing. A row-authorization test that
 * opens the gate is testing the harness.
 *
 * The three rulings under test are the three things twelve hand-written call sites did not agree on.
 */
class RowAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('row_auth_widgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label');
            $table->timestamps();
        });

        foreach (['alpha', 'beta', 'gamma'] as $label) {
            RowAuthTestWidget::query()->create(['label' => $label]);
        }
    }

    /** Bind a real cascade policy the way `CascadePolicyRegistrar::register()` does. */
    private function bindCascadePolicy(): void
    {
        $key = 'permission-cascade-policy:'.RowAuthTestWidget::class;

        $this->app->bind($key, fn () => new ConfiguredModelPolicy(RowAuthTestWidget::class));

        Gate::policy(RowAuthTestWidget::class, $key);
    }

    private function scoped(): Builder
    {
        return RowAuthorization::apply(RowAuthTestWidget::query(), RowAuthTestWidget::class);
    }

    public function test_ruling_1_fails_closed_when_no_policy_is_bound(): void
    {
        $this->assertNull(Gate::getPolicyFor(RowAuthTestWidget::class));
        $this->actingAs(new User);

        $this->assertSame(3, RowAuthTestWidget::query()->count(), 'the rows exist');
        $this->assertSame(0, $this->scoped()->count(), 'an unresolvable guard must yield none, not all');
    }

    public function test_ruling_1_fails_closed_when_the_bound_policy_is_not_a_cascade_policy(): void
    {
        // The case a `method_exists($policy, 'scopeForUser')` duck-type cannot tell from a real one:
        // this class HAS the method and returns the query untouched, so a duck-type returns every row.
        Gate::policy(RowAuthTestWidget::class, RowAuthTestNonCascadePolicy::class);
        $this->actingAs(new User);

        $this->assertSame(0, $this->scoped()->count());
    }

    public function test_an_actor_holding_no_token_sees_nothing_against_a_real_cascade_policy(): void
    {
        $this->bindCascadePolicy();
        $this->actingAs(new User);

        $this->assertSame(0, $this->scoped()->count());
    }

    public function test_an_unauthenticated_actor_sees_nothing(): void
    {
        $this->bindCascadePolicy();

        $this->assertSame(0, $this->scoped()->count());
    }

    /** The falsification: without this, an always-empty query would pass every test above. */
    public function test_an_actor_holding_the_class_view_token_sees_every_row(): void
    {
        $this->bindCascadePolicy();
        Gate::define(PermissionNamer::assemble(RowAuthTestWidget::class, 'view'), fn () => true);
        $this->actingAs(new User);

        $this->assertSame(3, $this->scoped()->count());
    }

    /**
     * RULING 2 — the constraint rides the RETURN.
     *
     * `FragmentQuery:47-49` discards `scopeForUser`'s return and relies on in-place mutation. Measured
     * 2026-08-30, that is safe only by accident: every branch happens to be `return $query->where…()`
     * and Eloquent mutates and hands back `$this`. This asserts the contract instead, so a future
     * branch returning a clone cannot silently unscope every caller.
     */
    public function test_ruling_2_returns_the_narrowed_builder(): void
    {
        $this->actingAs(new User);

        $returned = $this->scoped();

        $this->assertInstanceOf(Builder::class, $returned);
        $this->assertStringContainsString('1 = 0', $returned->toSql());
    }

    public function test_ruling_3_resolvable_reports_whether_the_guard_can_be_computed(): void
    {
        $this->assertFalse(RowAuthorization::resolvable(RowAuthTestWidget::class));

        $this->bindCascadePolicy();

        $this->assertTrue(RowAuthorization::resolvable(RowAuthTestWidget::class));
    }
}

class RowAuthTestWidget extends Model
{
    use HasUuids;

    protected $table = 'row_auth_widgets';

    protected $guarded = [];
}

/** Has the method, is not a cascade policy — the exact shape a duck-type admits. */
class RowAuthTestNonCascadePolicy
{
    public function scopeForUser($query, $user = null)
    {
        return $query;
    }
}
