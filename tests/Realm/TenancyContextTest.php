<?php

namespace Splicewire\Beam\Tests\Realm;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Realm\CentralTenancyContext;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * The TenancyContext port's core default (ADR-0236, host-binding-seam ticket 01), tested at the
 * interface: resolve the port, read the topology answers, swap the binding. Boots core the same
 * minimal way as {@see \Splicewire\Beam\Tests\CentralConnectionAliasTest} — the claim is that CORE
 * alone answers, unaided by any tenancy package.
 */
class TenancyContextTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PopcornServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            BeamServiceProvider::class,
        ];
    }

    public function test_core_binds_the_central_default(): void
    {
        $context = $this->app->make(TenancyContext::class);

        $this->assertInstanceOf(CentralTenancyContext::class, $context);
    }

    public function test_a_plain_host_reads_as_single_tenant_with_no_central_connection(): void
    {
        $context = $this->app->make(TenancyContext::class);

        $this->assertFalse($context->isMultiTenant());
        $this->assertFalse($context->isActive());
        $this->assertNull($context->currentTenant());
        $this->assertNull($context->currentTenantId());
        // Null, never the default connection's NAME — "the connection you would have used anyway".
        $this->assertNull($context->centralConnection());
    }

    public function test_a_declared_and_configured_central_connection_is_the_answer(): void
    {
        config()->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        config()->set('tenancy.database.central_connection', 'central');

        $context = $this->app->make(TenancyContext::class);

        $this->assertSame('central', $context->centralConnection());
        // Declared-and-resolvable IS the multi-tenant signal — even where central equals the boot
        // default (the tower shape that breaks the inequality heuristic).
        $this->assertTrue($context->isMultiTenant());
        // A configured split still initializes no tenant by itself.
        $this->assertFalse($context->isActive());
    }

    public function test_a_dangling_central_declaration_resolves_nothing(): void
    {
        // A non-`central` name on purpose: core's own connection ALIAS (beam-facade ticket 96)
        // registers `database.connections.central` at boot, so that one name can never dangle on a
        // beam host — which is the alias doing its job, and the port agreeing with
        // TenancyConnections on alias-backed hosts. Any other declared-but-undefined name dangles.
        config()->set('tenancy.database.central_connection', 'estate');

        $context = $this->app->make(TenancyContext::class);

        $this->assertNull($context->centralConnection());
        $this->assertFalse($context->isMultiTenant());
    }

    public function test_a_host_binding_supersedes_the_default(): void
    {
        $fake = new class implements TenancyContext
        {
            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return (object) ['id' => 't-1'];
            }

            public function currentTenantId(): int|string|null
            {
                return 't-1';
            }

            public function centralConnection(): ?string
            {
                return 'elsewhere';
            }
        };

        $this->app->bind(TenancyContext::class, fn () => $fake);

        $context = $this->app->make(TenancyContext::class);

        $this->assertTrue($context->isActive());
        $this->assertSame('t-1', $context->currentTenantId());
        $this->assertSame('elsewhere', $context->centralConnection());
    }
}
