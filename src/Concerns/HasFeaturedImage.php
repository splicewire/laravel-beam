<?php

namespace Schemastud\Beam\Concerns;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Opt-in single-featured-image mixin over {@see HasPrimaryImages}. A model that wants the
 * common "one featured/card image" affordance composes THIS trait instead of the bare base:
 * it contributes a `featured` slot (single-file collection with a `card` conversion) to the
 * primary-image map and adds `featured`-defaulted conveniences.
 *
 * It embeds {@see HasPrimaryImages} so a host uses a single `use HasFeaturedImage;` — the two
 * traits are not composed side-by-side (that would collide on `primaryImages()`). The base
 * machinery (collection/conversion registration, URL resolution) is inherited unchanged; only
 * the slot map is extended.
 *
 * Extending the map further stays open WITHOUT overriding `primaryImages()` and re-declaring
 * `featured`: a host overrides {@see additionalPrimaryImages()} to add `hero` / `og` / etc.,
 * and the `featured` slot is merged in automatically. (A host may still override
 * `primaryImages()` wholesale if it wants to drop or reshape `featured`.)
 */
trait HasFeaturedImage
{
    use HasPrimaryImages;

    /**
     * The `featured` slot plus any host-declared extras. Override
     * {@see additionalPrimaryImages()} to extend; override this method wholesale only to
     * reshape or drop `featured`.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function primaryImages(): array
    {
        return array_merge([
            'featured' => [
                'conversions' => ['card' => 800],
            ],
        ], $this->additionalPrimaryImages());
    }

    /**
     * Extra primary-image slots beyond `featured`. Empty by default; a host overrides to add
     * `hero` / `og` / etc. without re-declaring the `featured` slot.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function additionalPrimaryImages(): array
    {
        return [];
    }

    /**
     * The single featured Media, or null.
     */
    public function featuredImage(): ?Media
    {
        return $this->primaryImage('featured');
    }

    /**
     * The featured image's URL through media library. Returns the registered fallback URL
     * (or empty string) when unset.
     */
    public function featuredImageUrl(string $conversion = ''): string
    {
        return $this->primaryImageUrl('featured', $conversion);
    }
}
