<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Schemastud\Frame\Registry\ResourceActionScope;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Ops\Config\ConfigVersionsOp;
use Splicewire\Beam\Particle\Ops\Config\DiscardConfigDraftOp;
use Splicewire\Beam\Particle\Ops\Config\PublishConfigOp;
use Splicewire\Beam\Particle\Ops\Config\RestoreConfigVersionOp;
use Splicewire\Beam\Particle\Ops\Config\SaveConfigDraftOp;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Config\ConfigOperatorActor;
use Splicewire\Beam\Tests\Fixtures\Config\PanelsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections ticket 05 (lead ruling D, 2026-11-15): the operator-tier ops on `beam-config` ask
 * {@see ConfigSectionAccess::OPERATE}, a core-defined ability that asks the resource's OWN declared policy. Core never
 * names `entitlement:os.operate` on an op, because core references that key without defining it (a host does).
 *
 * What must hold: the ops and the resource answer every actor the same way. In a host that defines the staff key
 * the operator is admitted and a member refused. In a host that LACKS it the operator is refused by the ops exactly as
 * by the resource's own read, through the resource-policy path (Exec ADR-0001: an undefined key denies everyone).
 */
class ConfigOperateAbilityTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    private const OPS = [SaveConfigDraftOp::class, PublishConfigOp::class, DiscardConfigDraftOp::class, RestoreConfigVersionOp::class, ConfigVersionsOp::class];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', ConfigOperatorActor::class);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('config_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        ConfigOperatorActor::create(['name' => 'operator']);
        ConfigOperatorActor::create(['name' => 'member']);

        config()->set('panels', array_merge(require self::FIXTURES.'/package/panels.php', require self::FIXTURES.'/host/panels.php'));
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        app(ConfigSectionRegistry::class)->section(PanelsSection::class, owner: 'acme/panels', packageFile: realpath(self::FIXTURES.'/package/panels.php'));

        Particle::ops('beam-config', 'beam-config', self::OPS);
        Particle::mount('beam-config')->register();
    }

    private function actor(string $name): ConfigOperatorActor
    {
        return ConfigOperatorActor::query()->where('name', $name)->firstOrFail();
    }

    /** [ops answer, resource answer] for one actor (null = guest). */
    private function answers(?ConfigOperatorActor $actor): array
    {
        $resource = app(ParticleResourceRegistry::class)->find(ConfigSectionAccess::RESOURCE);
        $this->assertNotNull($resource);

        // Asked as the op controller asks it: AbilityResolver with the op's class-string subject (a subject-free op would
        // be projected onto `entitlement:{ability}`, which is why the ops name a subject).
        return [
            app(AbilityResolver::class)->allows($actor, ConfigSectionAccess::OPERATE, ConfigOverrideScope::class),
            app(ResourceVisibility::class)->readable($resource, $actor),
        ];
    }

    public function test_every_operator_tier_op_lists_as_a_frame_action_including_versions(): void // config-sections 10b
    {
        // The Config area calls each op through frame's useResourceAction, which only sees a mounted op that declares an
        // affordance. `versions` is a Read op and declared none until 10b, so the area could not list versions.
        $actions = collect(app(ParticleResourceRegistry::class)->definition(ConfigSectionAccess::RESOURCE)->actions)->keyBy('key');

        foreach (['save-draft', 'publish', 'discard-draft', 'restore', 'versions'] as $key) {
            $this->assertTrue($actions->has($key), "{$key} is a frame action on beam-config");
            $this->assertSame(ResourceActionScope::Resource, $actions[$key]->scope, $key);
        }
        $this->assertSame('Config versions', $actions['versions']->label);
        $this->assertSame('/beam-config/versions', $actions['versions']->url);
    }

    public function test_every_operator_tier_op_asks_the_core_defined_ability_not_the_staff_key(): void
    {
        foreach (self::OPS as $op) {
            $attribute = (new ReflectionClass($op))->getAttributes(ParticleOp::class)[0]->newInstance();
            $this->assertSame(ConfigSectionAccess::OPERATE, $attribute->ability, $op);
            $this->assertSame(ConfigOverrideScope::class, $attribute->abilityModel, $op);
        }

        $this->assertTrue(Gate::has(ConfigSectionAccess::OPERATE), 'core defines the ability');
    }

    public function test_a_host_that_defines_the_staff_key_admits_the_operator_and_refuses_a_member_and_a_guest(): void
    {
        Gate::define('entitlement:os.operate', fn (?ConfigOperatorActor $actor): bool => $actor?->name === 'operator');

        $this->assertSame([true, true], $this->answers($this->actor('operator')));
        $this->assertSame([false, false], $this->answers($this->actor('member')));
        $this->assertFalse($this->answers(null)[0], 'a guest never operates the config tier');

        $this->actingAs($this->actor('member'))
            ->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => json_encode('x')])
            ->assertForbidden();
    }

    public function test_a_host_lacking_the_staff_key_gets_the_same_answer_through_the_resource_policy_path(): void
    {
        $this->assertFalse(Gate::has('entitlement:os.operate'), 'this host defines no staff key');

        $this->assertSame([false, false], $this->answers($this->actor('operator')));
        $this->assertSame([false, false], $this->answers($this->actor('member')));

        $this->actingAs($this->actor('operator'))
            ->postJson('/beam-config/save-draft', ['root' => 'panels', 'pointer' => '/title', 'op' => 'set', 'value' => json_encode('x')])
            ->assertForbidden();
    }
}
