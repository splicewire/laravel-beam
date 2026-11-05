<?php

namespace Splicewire\Beam\Tests\Registries;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use Rushing\Popcorn\Registries\Registrant;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperModel;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperResource;
use Splicewire\Beam\Tests\TestCase;

/**
 * Who beam records as the registrant of a particle resource (docs-feedback ticket 06).
 *
 * Attribute-declared resources record `class:<FQCN>`, explicitly, because beam discovers them inside
 * its own provider scope and would otherwise attribute a host's resources to itself. A write that names
 * nobody records the open scope's package.
 */
class RegistrantVocabularyTest extends TestCase
{
    protected function scanTheRegistrarFixtures($app): void
    {
        $app['config']->set('beam.core.resources.discover_paths', [__DIR__.'/../Fixtures/Registrar']);
    }

    #[DefineEnvironment('scanTheRegistrarFixtures')]
    public function test_a_scanned_resource_names_its_declaring_class(): void
    {
        $this->assertSame(
            'class:'.ScannedPaperResource::class,
            $this->app->make(ParticleResourceRegistry::class)->registrantOf('scanned-papers'),
        );
    }

    public function test_register_class_names_the_declaring_class_even_inside_a_package_scope(): void
    {
        $registry = new ParticleResourceRegistry;

        Registrant::scoped('splicewire/laravel-beam', fn () => $registry->registerClass(ScannedPaperResource::class));

        $this->assertSame('class:'.ScannedPaperResource::class, $registry->registrantOf('scanned-papers'));
    }

    public function test_an_unnamed_write_records_the_open_scope_and_an_explicit_one_wins(): void
    {
        $registry = new ParticleResourceRegistry;

        Registrant::scoped('acme/host-package', function () use ($registry) {
            $registry->register(new ParticleResource(key: 'scoped-papers', backing: ScannedPaperModel::class));
            $registry->register(new ParticleResource(key: 'named-papers', backing: ScannedPaperModel::class), by: 'config:acme.papers');
        });

        $this->assertSame('acme/host-package', $registry->registrantOf('scoped-papers'));
        $this->assertSame('config:acme.papers', $registry->registrantOf('named-papers'));
    }
}
