<?php

namespace Schemastud\Beam\Concerns;

use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Declarative primary-image slots on top of spatie/laravel-medialibrary. A model declares
 * its slots via {@see primaryImages()} — a map of slot (single-file collection) name to
 * config — and the trait drives collection + conversion registration and media-first URL
 * resolution.
 *
 * This is the substrate media affordance: it is deliberately domain-agnostic. It knows
 * nothing about TMDB, legacy path columns, or any particular slot name — it only turns a
 * declared slot map into registered single-file collections + conversions and resolves a
 * slot's URL through media library. A host that needs a legacy dual-read, an ingest-from-
 * source path, or a specific `featured`/`hero`/`og` slot layers that ON TOP by overriding
 * {@see primaryImages()} and/or composing a mixin (see {@see HasFeaturedImage} for the
 * common single-featured-image opt-in). beam names no domain package — the dependency runs
 * one way (host -> beam), never the reverse.
 *
 * The base default is an EMPTY slot map: a bare `use HasPrimaryImages` registers nothing
 * until the host declares slots. This is the abstract-default reconciliation — the method
 * is concrete (so a host may adopt the trait without being forced to implement it, and the
 * base machinery has a sensible no-op default) but fully overridable (so a host that wants
 * its own slots, abstract-style, simply overrides it). A host that historically declared
 * `primaryImages()` abstract can adopt this trait and keep overriding the method verbatim.
 *
 * Config shape per slot: `{ conversions?: array<string, int>, fallback?: string|null }`.
 * Hosts are free to carry extra keys (source/column/route/…) in the config for their own
 * overridden behavior; the base ignores everything but `conversions` and `fallback`.
 */
trait HasPrimaryImages
{
    use InteractsWithMedia;

    /**
     * Map of slot name => config. Empty by default; a host overrides this to declare its
     * slots, or composes a mixin (e.g. {@see HasFeaturedImage}) that contributes one.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function primaryImages(): array
    {
        return [];
    }

    /**
     * The slot names this model exposes as primary images.
     *
     * @return array<int, string>
     */
    public function primaryImageCollections(): array
    {
        return array_keys($this->primaryImages());
    }

    public function registerMediaCollections(): void
    {
        foreach ($this->primaryImages() as $slot => $config) {
            $registration = $this->addMediaCollection($slot)->singleFile();

            $fallback = $config['fallback'] ?? null;
            if (is_string($fallback)) {
                $registration->useFallbackUrl($fallback);
            }
        }
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach ($this->primaryImages() as $slot => $config) {
            foreach (($config['conversions'] ?? []) as $name => $width) {
                $this->addMediaConversion($name)
                    ->width($width)
                    ->performOnCollections($slot);
            }
        }
    }

    /**
     * The single Media in a slot, or null.
     */
    public function primaryImage(string $slot): ?Media
    {
        return $this->getFirstMedia($slot);
    }

    /**
     * A slot's URL through media library only — no legacy path fallback. Returns the
     * registered fallback URL (or empty string) when the slot is unfilled. A host that
     * needs a legacy dual-read overrides this method.
     */
    public function primaryImageUrl(string $slot, string $conversion = ''): string
    {
        return $this->getFirstMediaUrl($slot, $conversion);
    }
}
