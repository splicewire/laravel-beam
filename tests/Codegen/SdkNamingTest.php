<?php

namespace Splicewire\Beam\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Codegen\SdkNaming;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;

/**
 * Locks the CONVENTION-FIRST SDK naming rules (client-sdk-regen "convention drives SDK names" pivot).
 * Both the generator and {@see SdkNameConventionAudit} name off exactly this
 * helper, so pinning it here pins what both produce.
 */
class SdkNamingTest extends TestCase
{
    private function op(string $method, string $path, ?string $tag = null): array
    {
        return ['method' => $method, 'path' => $path, 'meta' => ['tags' => $tag === null ? [] : [$tag]]];
    }

    public function test_post_collection_is_create_singular(): void
    {
        $this->assertSame('CreateIdea', (new SdkNaming)->classNameFor($this->op('POST', '/api/v1/studio/ideas')));
    }

    public function test_get_item_is_get_singular(): void
    {
        $this->assertSame('GetIdea', (new SdkNaming)->classNameFor($this->op('GET', '/api/v1/studio/ideas/{id}')));
    }

    public function test_get_collection_is_list_singular(): void
    {
        $this->assertSame('ListModel', (new SdkNaming)->classNameFor($this->op('GET', '/api/v1/models')));
    }

    public function test_put_and_patch_are_update(): void
    {
        $this->assertSame('UpdateComposition', (new SdkNaming)->classNameFor($this->op('PUT', '/api/v1/splice/compositions/{id}')));
        $this->assertSame('UpdateComposition', (new SdkNaming)->classNameFor($this->op('PATCH', '/api/v1/splice/compositions/{id}')));
    }

    public function test_delete_item_is_delete_singular(): void
    {
        $this->assertSame('DeleteFragment', (new SdkNaming)->classNameFor($this->op('DELETE', '/api/v1/fragments/{id}')));
    }

    public function test_trailing_action_segment_names_itself_studly(): void
    {
        $this->assertSame(
            'GenerateComposition',
            (new SdkNaming)->classNameFor($this->op('POST', '/api/v1/studio/ideas/{id}/generate-composition')),
        );
    }

    public function test_domain_is_the_studly_group_tag(): void
    {
        $this->assertSame('Studio', (new SdkNaming)->domainFor($this->op('POST', '/api/v1/studio/ideas', 'Studio')));
        $this->assertSame('ContextScopes', (new SdkNaming)->domainFor($this->op('GET', '/api/v1/context-scopes', 'Context Scopes')));
    }

    public function test_domain_is_null_when_untagged(): void
    {
        $this->assertNull((new SdkNaming)->domainFor($this->op('GET', '/api/v1/models')));
    }
}
