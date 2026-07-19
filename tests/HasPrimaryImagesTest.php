<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Splicewire\Beam\Concerns\HasFeaturedImage;
use Splicewire\Beam\Concerns\HasPrimaryImages;

class HasPrimaryImagesTest extends TestCase
{
    public function test_bare_base_trait_registers_no_slots_by_default(): void
    {
        $model = new BarePrimaryImagesFixture;

        $this->assertSame([], $model->primaryImageCollections());
        $this->assertSame([], $model->getRegisteredMediaCollections()->pluck('name')->all());
    }

    public function test_a_host_declared_slot_map_registers_single_file_collections(): void
    {
        $model = new HostSlotsFixture;

        $this->assertSame(['hero', 'og'], $model->primaryImageCollections());

        $collections = $model->getRegisteredMediaCollections();
        $this->assertSame(['hero', 'og'], $collections->pluck('name')->all());
        // singleFile() sets a collection size limit of 1
        $this->assertTrue($collections->firstWhere('name', 'hero')->collectionSizeLimit === 1);
    }

    public function test_declared_conversions_are_registered_for_their_slot(): void
    {
        $model = new HostSlotsFixture;
        $model->registerAllMediaConversions();

        $card = collect($model->mediaConversions)
            ->first(fn ($conversion) => $conversion->getName() === 'card');

        $this->assertNotNull($card, 'the "card" conversion should be registered');
        $this->assertContains('hero', $card->getPerformOnCollections());
    }

    public function test_featured_mixin_contributes_the_featured_slot(): void
    {
        $model = new FeaturedFixture;

        $this->assertSame(['featured'], $model->primaryImageCollections());
        $this->assertSame(
            ['featured'],
            $model->getRegisteredMediaCollections()->pluck('name')->all()
        );

        // the featured slot carries a `card` conversion
        $model->registerAllMediaConversions();
        $this->assertNotNull(
            collect($model->mediaConversions)->first(fn ($conversion) => $conversion->getName() === 'card')
        );
    }

    public function test_featured_mixin_merges_host_additional_slots_without_redeclaring_featured(): void
    {
        $model = new FeaturedWithExtrasFixture;

        // featured is contributed by the mixin; og is the host's additional slot
        $this->assertSame(['featured', 'og'], $model->primaryImageCollections());
        $this->assertSame(
            ['featured', 'og'],
            $model->getRegisteredMediaCollections()->pluck('name')->all()
        );
    }
}

class BarePrimaryImagesFixture extends Model implements HasMedia
{
    use HasPrimaryImages;
}

class HostSlotsFixture extends Model implements HasMedia
{
    use HasPrimaryImages;

    protected function primaryImages(): array
    {
        return [
            'hero' => ['conversions' => ['card' => 800]],
            'og' => ['conversions' => ['social' => 1200], 'fallback' => 'https://example.test/og.png'],
        ];
    }
}

class FeaturedFixture extends Model implements HasMedia
{
    use HasFeaturedImage;
}

class FeaturedWithExtrasFixture extends Model implements HasMedia
{
    use HasFeaturedImage;

    protected function additionalPrimaryImages(): array
    {
        return [
            'og' => ['conversions' => ['social' => 1200]],
        ];
    }
}
