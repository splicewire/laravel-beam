<?php

namespace Splicewire\Beam\Tests\Routing;

use Illuminate\Routing\Router;
use LogicException;
use Splicewire\Beam\Routing\ApiRoot;
use Splicewire\Beam\Tests\TestCase;

class ApiRootTest extends TestCase
{
    public function test_package_default_and_override_mount_real_routes(): void
    {
        $router = $this->app->make(Router::class);
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => ['root' => 'default']);
        });
        $this->getJson('/beam/widgets/ping')->assertOk()->assertJsonPath('root', 'default');

        config(['fixture.api_root' => '/custom/widgets/']);
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => ['root' => 'override']);
        });
        $this->getJson('/custom/widgets/ping')->assertOk()->assertJsonPath('root', 'override');
        $this->assertSame('beam/core', config('beam.core.api_root'));
        $this->assertSame('splice/graph', ApiRoot::for('fixture.tower.api_root', 'graph', 'splice'));
        $this->assertSame('stud/frame', ApiRoot::for('fixture.frame.api_root', 'frame', 'stud'));
    }

    public function test_an_earlier_site_catch_all_refuses_by_name_before_mounting(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('{path?}', fn () => 'site')->where('path', '.*')->name('site.catch-all');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('site.catch-all');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (): void {
            $this->fail('The catch-all must refuse before registering package routes.');
        });
    }

    public function test_an_excluded_tier_and_an_ordinary_parameter_do_not_refuse(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('{slug}', fn () => 'one segment');
        $router->get('{path}', fn () => 'site')->where('path', '(?!beam(?:/|$)).*');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => ['ok' => true]);
        });
        $this->getJson('/beam/widgets/ping')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_prefixed_catch_all_is_checked_under_the_active_group(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('operator/{path}', fn () => 'spa')->where('path', '.+')->name('operator.spa');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('operator.spa');
        $router->prefix('operator')->group(function (Router $router): void {
            ApiRoot::mount($router, 'fixture.api_root', 'widgets', fn () => null);
        });
    }
}
