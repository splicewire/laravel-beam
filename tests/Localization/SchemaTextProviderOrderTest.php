<?php

namespace Splicewire\Beam\Tests\Localization;

use Schemastud\DataSchemas\Contracts\SchemaText;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Localization\BeamSchemaText;
use Splicewire\Beam\Tests\TestCase;

/**
 * localization 09 — beam's text hook wins over laravel-data-schemas' identity default in EITHER provider order. Beam's
 * own TestCase registers beam first (FormTextTest asserts the binding there); this one registers laravel-data-schemas
 * first, so its `singletonIf` runs before beam's `singleton`.
 */
class SchemaTextProviderOrderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        $rest = array_values(array_filter($providers, static fn (string $p): bool => ! in_array($p, [BeamServiceProvider::class, LaravelDataSchemasServiceProvider::class], true)));

        return [LaravelDataSchemasServiceProvider::class, BeamServiceProvider::class, ...$rest];
    }

    public function test_beam_registered_after_data_schemas_still_binds_its_text_hook(): void
    {
        $providers = array_keys($this->app->getLoadedProviders());
        $this->assertLessThan(
            array_search(BeamServiceProvider::class, $providers, true),
            array_search(LaravelDataSchemasServiceProvider::class, $providers, true),
            'laravel-data-schemas must register first in this test',
        );

        $this->assertInstanceOf(BeamSchemaText::class, $this->app->make(SchemaText::class));
    }
}
