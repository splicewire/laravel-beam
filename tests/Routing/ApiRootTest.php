<?php

namespace Splicewire\Beam\Tests\Routing;

use Illuminate\Routing\Router;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_an_earlier_site_catch_all_refuses_by_name(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('{path?}', fn () => 'site')->where('path', '.*')->name('site.catch-all');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('site.catch-all');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => 'unreachable');
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
            ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
                $router->get('ping', fn () => 'unreachable');
            });
        });
    }

    public function test_fallback_other_domain_and_other_method_do_not_shadow(): void
    {
        $router = $this->app->make(Router::class);
        $router->fallback(fn () => 'fallback');
        $router->domain('other.test')->get('{path}', fn () => 'other')->where('path', '.*');
        $router->post('{postPath}', fn () => 'post only')->where('postPath', '.*');
        $router->domain('package.test')->group(function (Router $router): void {
            ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
                $router->get('ping', fn () => ['ok' => true]);
            });
        });
        $this->getJson('http://package.test/beam/widgets/ping')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_catch_all_on_a_dedicated_literal_host_does_not_shadow_an_undomained_root(): void
    {
        // data-schemas mounts its path-less schema door as a `{path}` catch-all constrained to `schemas.<host>`
        // (SchemaDoorMount). It answers only there, so it never shadows an undomained package root on the app host.
        config(['app.url' => 'http://app.test']);
        $router = $this->app->make(Router::class);
        $router->domain('schemas.app.test')->get('{path}', fn () => 'door')->where('path', '.*')->name('data-schemas.document');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => ['ok' => true]);
        });

        $this->getJson('http://app.test/beam/widgets/ping')->assertOk()->assertJsonPath('ok', true);
        $this->get('http://schemas.app.test/some/schema')->assertOk()->assertSee('door');
    }

    /** @return array<string, array{0: string, 1: string}> app.url, the catch-all's literal domain */
    public static function ownHostSpellings(): array
    {
        return [
            'same host' => ['http://app.test', 'app.test'],
            'case' => ['http://APP.test', 'app.TEST'],
            'port on app.url' => ['http://app.test:8000', 'app.test'],
            'trailing dot on the domain' => ['http://app.test', 'app.test.'],
        ];
    }

    #[DataProvider('ownHostSpellings')]
    public function test_a_catch_all_constrained_to_the_app_own_host_still_refuses(string $appUrl, string $domain): void
    {
        config(['app.url' => $appUrl]);
        $router = $this->app->make(Router::class);
        $router->domain($domain)->get('{path}', fn () => 'site')->where('path', '.*')->name('site.own-host');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('site.own-host');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => 'unreachable');
        });
    }

    public function test_a_patterned_catch_all_domain_still_refuses_an_undomained_root(): void
    {
        // A pattern can match the tenant hosts an undomained package route is served on.
        config(['app.url' => 'http://app.test']);
        $router = $this->app->make(Router::class);
        $router->domain('{tenant}.app.test')->get('{path}', fn () => 'tenant site')->where('path', '.*')->name('tenant.catch-all');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tenant.catch-all');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => 'unreachable');
        });
    }

    public function test_an_app_url_without_a_host_fails_closed(): void
    {
        config(['app.url' => '']);
        $router = $this->app->make(Router::class);
        $router->domain('schemas.app.test')->get('{path}', fn () => 'door')->where('path', '.*')->name('data-schemas.document');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('data-schemas.document');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => 'unreachable');
        });
    }

    public function test_an_empty_string_domain_catch_all_still_refuses(): void
    {
        // Laravel reads domain('') as no constraint: it matches every host, so it shadows like an undomained catch-all.
        config(['app.url' => 'http://app.test']);
        $router = $this->app->make(Router::class);
        $router->domain('')->get('{path}', fn () => 'site')->where('path', '.*')->name('site.empty-domain');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('site.empty-domain');
        ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
            $router->get('ping', fn () => 'unreachable');
        });
    }

    public function test_an_undomained_earlier_catch_all_still_refuses_a_domained_package_root(): void
    {
        // Unchanged behaviour: an undomained catch-all answers on every host, the package's domain included.
        config(['app.url' => 'http://app.test']);
        $router = $this->app->make(Router::class);
        $router->get('{path}', fn () => 'site')->where('path', '.*')->name('site.every-host');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('site.every-host');
        $router->domain('package.test')->group(function (Router $router): void {
            ApiRoot::mount($router, 'fixture.api_root', 'widgets', function (Router $router): void {
                $router->get('ping', fn () => 'unreachable');
            });
        });
    }

    public function test_null_and_empty_config_keep_the_package_default(): void
    {
        foreach ([null, '', '/'] as $value) {
            config(['fixture.api_root' => $value]);
            $this->assertSame('beam/widgets', ApiRoot::for('fixture.api_root', 'widgets'));
        }
    }
}
