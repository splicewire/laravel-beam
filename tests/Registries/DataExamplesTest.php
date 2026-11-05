<?php

namespace Splicewire\Beam\Tests\Registries;

use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Popcorn\Registries\Docs\DataExamples;
use Splicewire\Beam\Tests\TestCase;

/**
 * The registry pages' data examples are captured output (docs-feedback ticket 09). This checks the committed fixtures
 * and page blocks, and re-runs each example in this test's application, the one `composer docs:examples` boots. It
 * never writes: regenerate with `composer docs:examples -- --write`.
 */
class DataExamplesTest extends TestCase
{
    public function test_every_data_example_is_captured_placed_and_current_on_its_page(): void
    {
        $examples = DataExamples::forPackage(dirname(__DIR__, 2));

        $this->assertSame(
            ['beam.events.types/catalog-entry', 'beam.particle.resources/register-a-resource'],
            array_keys($examples->cases()),
        );
        $this->assertSame([], $examples->problems());
    }

    #[DataProvider('examples')]
    public function test_the_example_captures_what_the_page_shows(string $id): void
    {
        $this->assertSame([], DataExamples::forPackage(dirname(__DIR__, 2))->drift($id));
    }

    /** @return array<string, array{string}> */
    public static function examples(): array
    {
        $ids = array_keys(DataExamples::forPackage(dirname(__DIR__, 2))->cases());

        return array_combine($ids, array_map(fn (string $id) => [$id], $ids));
    }
}
