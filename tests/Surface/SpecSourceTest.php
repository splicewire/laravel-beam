<?php

namespace Splicewire\Beam\Tests\Surface;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Surface\MalformedSpecException;
use Splicewire\Beam\Surface\SpecSource;

/**
 * soc2-readiness-dogfood ticket 02 — the stack-blind half of the mechanism.
 *
 * **This class extends PHPUnit's TestCase, not beam's.** That is the assertion, not a shortcut: the
 * ticket requires `SpecSource` to parse a document with no application boot, and the only way to prove
 * that is to run it with no application available. If a Laravel concept ever leaks into the parser these
 * tests stop erroring in some subtle way — they fail outright.
 */
class SpecSourceTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__.'/fixtures/'.$name;
    }

    public function test_it_inventories_a_well_formed_spec(): void
    {
        $inventory = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory();

        $this->assertSame('Widgets API', $inventory->title);
        $this->assertSame('1.4.0', $inventory->version);
        $this->assertSame(['bearerAuth', 'apiKey'], $inventory->securitySchemes);
        $this->assertSame(['bearerAuth'], $inventory->defaultSecurity);

        $this->assertSame([
            'GET /api/v1/widgets',
            'GET /api/v1/widgets/{id}',
            'GET /api/v1/widgets/{id}/optional',
            'GET /up',
            'POST /api/v1/widgets',
        ], $inventory->signatures());
    }

    public function test_it_reads_declared_request_and_response_shapes(): void
    {
        $inventory = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory();

        $store = $inventory->seam('POST', '/api/v1/widgets');
        $this->assertSame('WidgetData', $store->requestShape);
        $this->assertSame(['201' => 'WidgetData'], $store->responseShapes);

        $index = $inventory->seam('GET', '/api/v1/widgets');
        $this->assertNull($index->requestShape);
        $this->assertSame(['200' => 'WidgetData[]'], $index->responseShapes);
        $this->assertSame(['Widgets'], $index->tags);
    }

    public function test_an_operation_security_block_overrides_the_document_default(): void
    {
        $inventory = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory();

        $this->assertSame(['bearerAuth'], $inventory->seam('GET', '/api/v1/widgets')->security);
        $this->assertSame(['apiKey'], $inventory->seam('GET', '/api/v1/widgets/{id}')->security);
    }

    /**
     * `security: []` is a CLAIM ("this is public"), and an undeclared security block is a GAP ("the
     * document never said"). Both are falsy in PHP and both would collapse to the same thing under a
     * lazier model — which is exactly how an unaudited surface starts reading as a documented one.
     */
    public function test_declared_public_is_distinguishable_from_undeclared(): void
    {
        $declaredPublic = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory()->seam('GET', '/up');
        $this->assertSame([], $declaredPublic->security);
        $this->assertFalse($declaredPublic->claimsAuthentication());

        $undeclared = SpecSource::fromFile($this->fixture('no-security-schemes.json'))->inventory()->seam('GET', '/v2/orders');
        $this->assertNull($undeclared->security);
        $this->assertFalse($undeclared->claimsAuthentication());
    }

    /** An empty Security Requirement Object means "auth optional here" — a claim of auth it undercuts. */
    public function test_an_optional_security_requirement_is_reported_as_optional(): void
    {
        $seam = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory()
            ->seam('GET', '/api/v1/widgets/{id}/optional');

        $this->assertSame(['bearerAuth'], $seam->security);
        $this->assertTrue($seam->securityOptional);
        $this->assertFalse($seam->claimsAuthentication());
    }

    public function test_a_spec_declaring_no_security_schemes_still_inventories(): void
    {
        $inventory = SpecSource::fromFile($this->fixture('no-security-schemes.json'))->inventory();

        $this->assertSame([], $inventory->securitySchemes);
        $this->assertNull($inventory->defaultSecurity);
        $this->assertSame(['DELETE /v2/orders', 'GET /v2/orders'], $inventory->signatures());
        $this->assertCount(2, $inventory->undeclaredSecurity());
    }

    public function test_a_path_item_key_that_is_not_an_operation_is_not_a_seam(): void
    {
        $inventory = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory();

        // `/api/v1/widgets` carries a path-level `parameters` list; it must not become a seam.
        $this->assertNull($inventory->seam('PARAMETERS', '/api/v1/widgets'));
        $this->assertCount(5, $inventory->seams);
    }

    public function test_it_rejects_a_document_with_no_paths(): void
    {
        $this->expectException(MalformedSpecException::class);

        SpecSource::fromArray(['openapi' => '3.1.0', 'info' => ['title' => 'Nothing']])->inventory();
    }

    public function test_it_rejects_a_document_that_declares_no_openapi_version(): void
    {
        $this->expectException(MalformedSpecException::class);

        SpecSource::fromArray(['paths' => []])->inventory();
    }

    public function test_it_rejects_unparseable_json(): void
    {
        $this->expectException(MalformedSpecException::class);

        SpecSource::fromJson('{"openapi": ');
    }

    public function test_it_rejects_an_unreadable_file(): void
    {
        $this->expectException(MalformedSpecException::class);

        SpecSource::fromFile(__DIR__.'/fixtures/does-not-exist.yaml');
    }

    public function test_it_rejects_an_unrecognized_extension(): void
    {
        $this->expectException(MalformedSpecException::class);

        SpecSource::fromFile(__FILE__);
    }

    /** The inventory is diffable: an unchanged document parses to an identical seam ordering. */
    public function test_seam_ordering_is_stable(): void
    {
        $first = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory()->signatures();
        $second = SpecSource::fromFile($this->fixture('well-formed.yaml'))->inventory()->signatures();

        $this->assertSame($first, $second);
    }
}
