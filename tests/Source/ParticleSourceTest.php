<?php

namespace Splicewire\Beam\Tests\Source;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Source\ParticleSource;
use Splicewire\Beam\Source\SourceKind;

/**
 * The typed source descriptor (ADR-0161 ticket 01): each named constructor produces a
 * coherent {@see ParticleSource} whose kind + parts match its authority, and the
 * local/foreign split routes hydration.
 *
 * A pure value; the test extends PHPUnit's base case (no testbench boot) so it verifies the
 * descriptor grammar without the container — and stays green while the concurrent beam⇄frame
 * refactor leaves beam's own bootstrap harness mid-migration.
 */
class ParticleSourceTest extends TestCase
{
    public function test_local_source_carries_only_the_ref_and_is_not_foreign(): void
    {
        $source = ParticleSource::local('content/article/7');

        $this->assertSame(SourceKind::Local, $source->kind);
        $this->assertSame('content/article/7', $source->ref);
        $this->assertNull($source->providerHandle);
        $this->assertNull($source->authority);
        $this->assertNull($source->externalRef);
        $this->assertTrue($source->isLocal());
        $this->assertFalse($source->isForeign());
    }

    public function test_conduit_source_carries_the_provider_handle_and_is_foreign(): void
    {
        $source = ParticleSource::conduit('numero:default.explain-number', 'numero:default.explain-number', 'content/article');

        $this->assertSame(SourceKind::Conduit, $source->kind);
        $this->assertSame('numero:default.explain-number', $source->providerHandle);
        $this->assertSame('content/article', $source->targetSchemaId);
        $this->assertFalse($source->isLocal());
        $this->assertTrue($source->isForeign());
    }

    public function test_federated_source_carries_authority_and_external_ref_and_is_foreign(): void
    {
        $source = ParticleSource::federated('federation:grant-42:frag-9', 'grant-42', 'frag-9', 'content/article');

        $this->assertSame(SourceKind::Federated, $source->kind);
        $this->assertSame('grant-42', $source->authority);
        $this->assertSame('frag-9', $source->externalRef);
        $this->assertSame('content/article', $source->targetSchemaId);
        $this->assertTrue($source->isForeign());
    }
}
