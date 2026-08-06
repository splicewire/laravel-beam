<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

trait HasMetaAttribute
{
    public function initializeHasMetaAttribute()
    {
        $this->casts['meta'] = SchemalessAttributes::class;
    }

    public function metaArray(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->meta->toArray(),
            set: fn ($value) => ['meta' => $value],
        );
    }

    public function scopeWithMeta(): Builder
    {
        return $this->meta->modelScope();
    }
}
