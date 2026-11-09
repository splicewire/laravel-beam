<?php

namespace Splicewire\Beam\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\JsonPointer;

/**
 * The one boundary where a public JSON pointer becomes a DataOverlay JSONPath (ADR-0245 decision 2): a tier's `set`
 * and explicit-null ops are applied with DataOverlay's `override`, through {@see JsonPointer::toJsonPath()}. A config
 * `unset` never reaches DataOverlay: it removes the override from its tier, and the ladder refolds the tiers below.
 *
 * Run against DataOverlay's real mutator, because the claim is about what that mutator addresses.
 */
class JsonPointerOverlayBoundaryTest extends TestCase
{
    public function test_a_dotted_key_stays_one_segment(): void
    {
        $this->assertSame("\$['google.cse.query']['unit_cost_usd']", JsonPointer::toJsonPath('/google.cse.query/unit_cost_usd'));
        $this->assertSame("\$['a/b']['c~d']", JsonPointer::toJsonPath('/a~1b/c~0d'));
        $this->assertSame('$', JsonPointer::toJsonPath(''));
    }

    public function test_a_set_reaches_the_dotted_node_and_no_other(): void
    {
        $doc = ['google.cse.query' => ['unit_cost_usd' => 0.005, 'label' => 'Google web search'], 'google' => ['cse' => 'untouched']];

        (new JsonPathMutator)->override($doc, JsonPointer::toJsonPath('/google.cse.query/unit_cost_usd'), 0.004);

        $this->assertSame(['google.cse.query' => ['unit_cost_usd' => 0.004, 'label' => 'Google web search'], 'google' => ['cse' => 'untouched']], $doc);
    }

    public function test_an_explicit_null_is_a_retained_null_not_a_deletion(): void
    {
        $doc = ['google.cse.query' => ['unit_cost_usd' => 0.005]];

        (new JsonPathMutator)->override($doc, JsonPointer::toJsonPath('/google.cse.query/unit_cost_usd'), null);

        $this->assertArrayHasKey('unit_cost_usd', $doc['google.cse.query']);
        $this->assertNull($doc['google.cse.query']['unit_cost_usd']);
    }

    public function test_a_set_on_an_absent_dotted_key_creates_it(): void
    {
        $doc = ['openai.tokens' => ['label' => 'OpenAI tokens']];

        (new JsonPathMutator)->override($doc, JsonPointer::toJsonPath('/search.rerank'), ['label' => 'Search rerank']);

        $this->assertSame(['label' => 'Search rerank'], $doc['search.rerank']);
    }

    public function test_a_key_dataoverlay_cannot_address_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("[it's]");

        JsonPointer::toJsonPath("/it's");
    }
}
