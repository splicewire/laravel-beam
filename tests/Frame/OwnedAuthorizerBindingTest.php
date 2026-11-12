<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Support\ServiceProvider;
use Schemastud\Frame\Authorization\ResourceAuthorizer;
use Splicewire\Beam\Tests\TestCase;

class OwnedAuthorizerBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), HostAuthorizerProvider::class];
    }

    public function test_an_explicit_host_authorizer_survives_package_boot(): void
    {
        $this->assertInstanceOf(HostResourceAuthorizer::class, app(ResourceAuthorizer::class));
    }
}

class HostAuthorizerProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResourceAuthorizer::class, HostResourceAuthorizer::class);
    }
}

class HostResourceAuthorizer extends ResourceAuthorizer {}
