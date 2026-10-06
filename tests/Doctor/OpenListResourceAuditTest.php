<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\Gate;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Doctor\OpenListResourceAudit;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetPolicy;
use Splicewire\Beam\Tests\TestCase;

/**
 * The ratchet on the list read's one remaining open pass (launch security row 51a71469): a model-backed, row-unscoped
 * resource whose bound policy defines NO viewAny lists to any signed-in actor. Each one must be accepted by name, with
 * its reason, or the doctor warns, so a new one cannot silently join.
 */
class OpenListResourceAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = new ParticleResourceRegistry;
    }

    private function declare(string $key): void
    {
        $this->resources->register(new ParticleResource(key: $key, backing: Gadget::class, data: GadgetData::class, frame: false));
    }

    private function audit(): OpenListResourceAudit
    {
        return new OpenListResourceAudit($this->resources, ResourceReadGuard::forApp());
    }

    public function test_an_unaccepted_open_list_warns_by_name(): void
    {
        Gate::policy(Gadget::class, OpenListNoViewAnyPolicy::class);
        $this->declare('gadgets');

        $findings = $this->audit()->run();

        $this->assertSame([DoctorStatus::Warn], array_map(fn ($f) => $f->status, $findings));
        $this->assertStringContainsString('[gadgets]', $findings[0]->detail);
        $this->assertStringContainsString(OpenListNoViewAnyPolicy::class, $findings[0]->detail);
    }

    public function test_an_accepted_open_list_passes_with_its_reason(): void
    {
        Gate::policy(Gadget::class, OpenListNoViewAnyPolicy::class);
        config(['beam.core.reads.open_list' => ['gadgets' => 'rows are scoped by the handler']]);
        $this->declare('gadgets');

        $findings = $this->audit()->run();

        $this->assertSame([DoctorStatus::Pass], array_map(fn ($f) => $f->status, $findings));
        $this->assertStringContainsString('rows are scoped by the handler', $findings[0]->detail);
    }

    public function test_a_policy_with_view_any_is_not_an_open_list(): void
    {
        Gate::policy(Gadget::class, GadgetPolicy::class);
        $this->declare('gadgets');

        $findings = $this->audit()->run();

        $this->assertSame([DoctorStatus::Pass], array_map(fn ($f) => $f->status, $findings));
        $this->assertStringContainsString('no model-backed resource', $findings[0]->detail);
    }

    /** saved-filters declares its viewAny deliberately, so beam accepts nothing by name (follow-on to row 51a71469). */
    public function test_beam_accepts_no_open_list_by_name(): void
    {
        $this->assertSame([], OpenListResourceAudit::BEAM_ACCEPTED);
        $this->assertArrayNotHasKey('saved-filters', OpenListResourceAudit::accepted());
    }

    public function test_saved_filters_is_not_an_open_list(): void
    {
        $this->assertTrue(method_exists(\Splicewire\Beam\Filters\SavedFilterPolicy::class, 'viewAny'));
        $saved = collect(app(ParticleResourceRegistry::class)->all())->firstWhere('key', 'saved-filters');
        if ($saved !== null) {
            $this->assertTrue(ResourceReadGuard::forApp()->policyHasViewAny($saved));
        }
    }
}

class OpenListNoViewAnyPolicy
{
    public function view(mixed $user, mixed $model): bool
    {
        return true;
    }
}
