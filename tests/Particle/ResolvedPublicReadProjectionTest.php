<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

final class ResolvedPublicReadFullData extends Data
{
    public function __construct(public string $id, public string $label, public string $privateNote) {}
}

final class ResolvedPublicReadNarrowData extends Data
{
    public function __construct(public string $id, public string $label) {}
}

final class ResolvedPublicReadBacking implements ResolvesRecord
{
    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return $id === 'present'
            ? new ResolvedRecord(new ResolvedPublicReadFullData($id, 'Public label', 'private value'), 'https://schemas.test/resolved.json')
            : null;
    }
}

final class ResolvedPublicReadProjectionTest extends TestCase
{
    public function test_a_guest_receives_only_the_narrower_public_read_over_rest(): void
    {
        $resource = $this->resource();
        app(ParticleResourceRegistry::class)->register($resource);
        Route::get('/resolved-public-read/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, $resource->key);

        $this->getJson('/resolved-public-read/present')
            ->assertOk()
            ->assertJsonPath('data.id', 'present')
            ->assertJsonPath('data.label', 'Public label')
            ->assertJsonPath('data.schemaRef', 'https://schemas.test/resolved.json')
            ->assertJsonMissingPath('data.privateNote');
    }

    public function test_a_signed_in_reader_keeps_the_full_resolved_record_over_rest(): void
    {
        Gate::define('resolved-public-read', static fn () => true);
        $resource = $this->resource();
        app(ParticleResourceRegistry::class)->register($resource);
        Route::get('/resolved-public-read/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, $resource->key);

        $this->actingAs(new GenericUser(['id' => 'reader']))
            ->getJson('/resolved-public-read/present')
            ->assertOk()
            ->assertJsonPath('data.id', 'present')
            ->assertJsonPath('data.label', 'Public label')
            ->assertJsonPath('data.privateNote', 'private value')
            ->assertJsonPath('data.schemaRef', 'https://schemas.test/resolved.json');
    }

    public function test_a_guest_receives_only_the_narrower_public_read_in_frame(): void
    {
        $resource = $this->resource();
        app(ParticleResourceRegistry::class)->register($resource);
        $record = app(ParticleFrameResourceHandler::class)->forActor(null)->show($resource->toResourceDefinition(), 'present');

        $this->assertSame([
            'id' => 'present',
            'label' => 'Public label',
            'schemaRef' => 'https://schemas.test/resolved.json',
        ], $record);
    }

    public function test_a_signed_in_reader_keeps_the_full_resolved_record_in_frame(): void
    {
        Gate::define('resolved-public-read', static fn () => true);
        $resource = $this->resource();
        app(ParticleResourceRegistry::class)->register($resource);
        $record = app(ParticleFrameResourceHandler::class)->forActor(new GenericUser(['id' => 'reader']))
            ->show($resource->toResourceDefinition(), 'present');

        $this->assertSame([
            'id' => 'present',
            'label' => 'Public label',
            'privateNote' => 'private value',
            'schemaRef' => 'https://schemas.test/resolved.json',
        ], $record);
    }

    public function test_a_denied_signed_in_reader_gets_the_public_projection_on_both_resolved_show_paths(): void
    {
        Gate::define('resolved-public-read', static fn () => false);
        $resource = $this->resource();
        app(ParticleResourceRegistry::class)->register($resource);
        Route::get('/resolved-public-read/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, $resource->key);
        $reader = new GenericUser(['id' => 'reader']);

        $this->actingAs($reader)
            ->getJson('/resolved-public-read/present')
            ->assertOk()
            ->assertJsonPath('data.id', 'present')
            ->assertJsonPath('data.label', 'Public label')
            ->assertJsonPath('data.schemaRef', 'https://schemas.test/resolved.json')
            ->assertJsonMissingPath('data.privateNote');

        $record = app(ParticleFrameResourceHandler::class)->forActor($reader)
            ->show($resource->toResourceDefinition(), 'present');

        $this->assertSame([
            'id' => 'present',
            'label' => 'Public label',
            'schemaRef' => 'https://schemas.test/resolved.json',
        ], $record);
    }

    public function test_a_denied_signed_in_reader_without_public_read_is_still_refused_on_both_resolved_show_paths(): void
    {
        Gate::define('resolved-public-read', static fn () => false);
        $resource = $this->resource(declaresPublicRead: false);
        app(ParticleResourceRegistry::class)->register($resource);
        Route::get('/resolved-private-read/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, $resource->key);
        $reader = new GenericUser(['id' => 'reader']);

        $this->actingAs($reader)->getJson('/resolved-private-read/present')->assertForbidden();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ParticleFrameResourceHandler::class)->forActor($reader)
            ->show($resource->toResourceDefinition(), 'present');
    }

    private function resource(bool $declaresPublicRead = true): ParticleResource
    {
        return new ParticleResource(
            key: 'resolved-public-read',
            backing: ResolvedPublicReadBacking::class,
            data: ResolvedPublicReadFullData::class,
            publicRead: $declaresPublicRead ? ResolvedPublicReadNarrowData::class : null,
            policy: 'resolved-public-read',
            input: false,
            readOnly: true,
        );
    }
}
